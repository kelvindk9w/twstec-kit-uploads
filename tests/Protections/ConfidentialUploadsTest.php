<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Confidential\ConfidentialStorage;
use Twstec\Kit\Uploads\Confidential\EncryptionKey;
use Twstec\Kit\Uploads\Confidential\Exceptions\ConfidentialStorageUnavailableException;
use Twstec\Kit\Uploads\Confidential\Exceptions\UndecryptableUploadException;
use Twstec\Kit\Uploads\Confidential\Keyring;
use Twstec\Kit\Uploads\Confidential\StreamCipher;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;
use Twstec\Kit\Uploads\UploadsServiceProvider;

// =============================================================================
// UPLOADS CONFIDENCIAIS — CIFRA EM REPOUSO (aplicação limpa):
//
// - o objeto cru no armazenamento não é o original nem contém trecho dele;
// - decifra de volta byte a byte (inclusive arquivo de vários blocos);
// - adulterar, cortar ou trocar o objeto pelo de outro upload → não decifra;
// - sem chave (ou com chave inválida, ou igual à APP_KEY): o upload
//   confidencial é RECUSADO, nada vai para o disco nem para o banco, e em
//   produção o boot avisa no log — sem nunca escrever a chave em log;
// - o que não é confidencial continua exatamente como antes.
// =============================================================================

function enviaConfidencial(string $bytes, ?User $quem = null, string $nome = 'contrato.pdf'): Upload
{
    $quem ??= User::fixture(['email_verified_at' => now()]);

    return Accounts::actingAs(
        app(AccountService::class)->personalAccountOf($quem),
        fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado($bytes, $nome), classification: UploadClassification::Confidential),
        $quem,
    );
}

beforeEach(function (): void {
    ligarConfidenciais();
});

it('o objeto cru no armazenamento NÃO é o original nem contém trecho dele; decifrado, volta idêntico', function (): void {
    $original = pdfConfidencial();
    $upload = enviaConfidencial($original);

    $cru = objetoCru($upload);

    expect($upload->classification)->toBe(UploadClassification::Confidential)
        ->and($upload->path)->toEndWith('.pdf.enc')
        ->and($cru)->not->toBe($original)
        ->and($cru)->not->toContain('SEGREDO-DO-TITULAR-7Q4Z')
        ->and($cru)->not->toContain('%PDF')
        ->and(objetoContemTrechoDo($cru, $original))->toBeFalse()
        // O formato: a marca, a versão e o id da chave no cabeçalho — o mesmo
        // id gravado no registro.
        ->and(substr($cru, 0, 8))->toBe(StreamCipher::MAGIC)
        ->and(ord($cru[8]))->toBe(StreamCipher::VERSION)
        ->and(bin2hex(substr($cru, 9, 8)))->toBe($upload->encryption_key_id)
        ->and($upload->encryption_key_id)->toBe(Keyring::fromConfig()->current()->id)
        // Tamanho e hash são do conteúdo (o que o cliente confere).
        ->and($upload->size)->toBe(strlen($original))
        ->and($upload->sha256)->toBe(hash('sha256', $original))
        ->and(conteudoDecifrado($upload))->toBe($original);
});

it('arquivo de VÁRIOS blocos (cifra em fluxo) decifra idêntico, e o cru não traz nenhum bloco em claro', function (): void {
    $original = pdfConfidencial('MARCA-GRANDE-K2', 300 * 1024);
    $upload = enviaConfidencial($original);

    $cru = objetoCru($upload);
    $blocos = (int) ceil(strlen($original) / StreamCipher::DEFAULT_CHUNK_BYTES);

    expect($blocos)->toBeGreaterThan(4)
        ->and(strlen($cru))->toBe(StreamCipher::PREFIX_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + strlen($original) + $blocos * SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES)
        ->and($cru)->not->toContain('MARCA-GRANDE-K2')
        ->and(conteudoDecifrado($upload))->toBe($original);
});

it('cada arquivo tem o próprio nonce: o mesmo conteúdo enviado duas vezes vira dois objetos diferentes', function (): void {
    $original = pdfConfidencial();

    $a = objetoCru(enviaConfidencial($original));
    $b = objetoCru(enviaConfidencial($original));

    expect(substr($a, StreamCipher::PREFIX_BYTES))->not->toBe(substr($b, StreamCipher::PREFIX_BYTES));
});

it('adulterar um byte, cortar o fim ou acrescentar depois do fim: não decifra (e nada sai sem autenticar)', function (string $estrago, string $motivo): void {
    $original = pdfConfidencial('ADULTERA', 150 * 1024);
    $upload = enviaConfidencial($original);
    $cru = objetoCru($upload);

    $adulterado = match ($estrago) {
        'byte' => substr_replace($cru, chr(ord($cru[90000]) ^ 0x01), 90000, 1),
        'corte' => substr($cru, 0, -100),
        'sobra' => $cru.'xyz',
        'cabecalho' => substr_replace($cru, chr(ord($cru[20]) ^ 0x01), 20, 1),
    };

    Storage::disk('local')->put((string) $upload->path, $adulterado);

    $saida = '';

    try {
        app(ConfidentialStorage::class)->stream($upload, function (string $pedaco) use (&$saida): void {
            $saida .= $pedaco;
        });

        $this->fail('Decifrou um objeto adulterado.');
    } catch (UndecryptableUploadException $exception) {
        expect($exception->reason)->toBe($motivo);
    }

    // O que saiu antes do estrago é prefixo autêntico do original — nunca
    // byte adulterado.
    expect(str_starts_with($original, $saida))->toBeTrue();
})->with([
    'um bit no meio' => ['byte', 'authentication_failed'],
    'cortado no fim' => ['corte', 'authentication_failed'],
    'bytes depois do fim' => ['sobra', 'authentication_failed'],
    'tamanho de bloco do cabeçalho' => ['cabecalho', 'authentication_failed'],
]);

it('cortar exatamente o último bloco (sem a marca FINAL) é detectado como truncado', function (): void {
    $original = pdfConfidencial('TRUNCA', 2 * StreamCipher::DEFAULT_CHUNK_BYTES + 10);
    $upload = enviaConfidencial($original);
    $cru = objetoCru($upload);
    $ultimo = strlen($original) % StreamCipher::DEFAULT_CHUNK_BYTES;

    expect($ultimo)->toBeGreaterThan(0);

    // Sai o último bloco inteiro (o resto do texto + 17 de autenticação): os
    // blocos que ficam autenticam, mas falta a marca FINAL.
    Storage::disk('local')->put((string) $upload->path, substr($cru, 0, -($ultimo + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES)));

    expect(fn () => conteudoDecifrado($upload))->toThrow(UndecryptableUploadException::class, 'truncated');
});

it('qualquer coisa DEPOIS do bloco FINAL é recusada, mesmo com o último bloco cheio', function (): void {
    $original = pdfConfidencial('CHEIO', StreamCipher::DEFAULT_CHUNK_BYTES);
    $original .= str_repeat("\n", (2 * StreamCipher::DEFAULT_CHUNK_BYTES) - strlen($original));
    $upload = enviaConfidencial($original);

    expect(strlen($original) % StreamCipher::DEFAULT_CHUNK_BYTES)->toBe(0);

    Storage::disk('local')->put((string) $upload->path, objetoCru($upload).str_repeat('x', 40));

    expect(fn () => conteudoDecifrado($upload))->toThrow(UndecryptableUploadException::class, 'data_after_final');
});

it('o objeto cifrado de OUTRO upload, posto no lugar, não decifra (cada arquivo é amarrado ao seu registro)', function (): void {
    $doTitular = enviaConfidencial(pdfConfidencial('DO-TITULAR'));
    $doAtacante = enviaConfidencial(pdfConfidencial('DO-ATACANTE'));

    Storage::disk('local')->put((string) $doAtacante->path, objetoCru($doTitular));

    expect(fn () => conteudoDecifrado($doAtacante))->toThrow(UndecryptableUploadException::class, 'authentication_failed');
});

it('SEM CHAVE: o upload confidencial é RECUSADO (falha fechada) — nada no disco, nada no banco', function (?string $chave, string $motivo): void {
    config(['uploads.confidential.key' => $chave === 'APP_KEY' ? config('app.key') : $chave]);
    Log::spy();

    expect(fn () => enviaConfidencial(pdfConfidencial()))->toThrow(ConfidentialStorageUnavailableException::class);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Accounts::asSystem('teste', fn () => Upload::query()->count()))->toBe(0)
        ->and(Keyring::fromConfig()->status())->toBe($motivo);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem, array $contexto = []): bool => $mensagem === 'upload.rejected' && ($contexto['reason'] ?? null) === 'encryption_unavailable' && ($contexto['detail'] ?? null) === $motivo)->once();
})->with([
    'ausente' => [null, Keyring::MISSING],
    'vazia' => ['', Keyring::MISSING],
    'formato inválido' => ['nao-e-base64', Keyring::INVALID],
    'curta demais' => ['base64:'.base64_encode('curta'), Keyring::INVALID],
    'a própria APP_KEY' => ['APP_KEY', Keyring::SAME_AS_APP_KEY],
]);

it('a recusa sem chave responde 503 na API (não 422, não 500), sem gravar nada', function (): void {
    config(['uploads.confidential.key' => null, 'uploads.classification.default' => 'confidential']);

    $this->postUpload($this->owner(), fixtureArquivoEnviado(pdfConfidencial(), 'contrato.pdf'))
        ->assertStatus(503)
        ->assertJsonPath('message', __('uploads.confidential.unavailable'));

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('o padrão do projeto (`uploads.classification.default`) vale quando a chamada não declara', function (): void {
    config(['uploads.classification.default' => 'confidential']);

    $upload = $this->inAccountOf(null, fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(pdfConfidencial(), 'x.pdf')));

    expect($upload->isConfidential())->toBeTrue()
        ->and(objetoCru($upload))->not->toContain('SEGREDO-DO-TITULAR-7Q4Z');
});

it('classificação desconhecida no padrão é erro — nunca um rebaixamento silencioso para "em claro"', function (): void {
    config(['uploads.classification.default' => 'secreto']);

    expect(fn () => $this->inAccountOf(null, fn () => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(pdfConfidencial(), 'x.pdf'))))
        ->toThrow(ValueError::class);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('o que NÃO é confidencial continua igual: private por padrão, arquivo em claro, URL assinada do disco', function (): void {
    $original = pdfConfidencial('COMUM');
    $upload = $this->inAccountOf(null, fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado($original, 'comum.pdf')));

    expect($upload->classification)->toBe(UploadClassification::Private)
        ->and($upload->encryption_key_id)->toBeNull()
        ->and($upload->path)->toEndWith('.pdf')
        ->and(objetoCru($upload))->toBe($original);
});

it('chave errada (não configurada) não decifra: o id da chave do cabeçalho não acha chave', function (): void {
    $upload = enviaConfidencial(pdfConfidencial());

    ligarConfidenciais();

    expect(fn () => conteudoDecifrado($upload))->toThrow(UndecryptableUploadException::class, 'unknown_key');
});

it('a chave nunca aparece em log, dump ou fila', function (): void {
    $chave = ligarConfidenciais();
    $bytes = (string) Keyring::decode($chave);
    $registros = [];

    Log::listen(function ($evento) use (&$registros): void {
        $registros[] = $evento->message.' '.json_encode($evento->context);
    });

    $upload = enviaConfidencial(pdfConfidencial());
    conteudoDecifrado($upload);

    $chaveDerivada = Keyring::fromConfig()->current();

    expect(implode("\n", $registros))->not->toContain(substr($chave, 7))
        ->and(print_r($chaveDerivada, true))->not->toContain($chaveDerivada->material())
        ->and(print_r($chaveDerivada, true))->toContain('[REDACTED]')
        ->and(fn () => serialize($chaveDerivada))->toThrow(LogicException::class)
        ->and($chaveDerivada->material())->not->toBe($bytes)
        ->and(strlen($chaveDerivada->id))->toBe(16)
        ->and(EncryptionKey::derive($bytes)->id)->toBe($chaveDerivada->id);
});

it('EM PRODUÇÃO, sem chave utilizável, o boot avisa no log (o motivo, nunca a chave)', function (?string $chave, string $trecho): void {
    config(['uploads.confidential.key' => $chave === 'APP_KEY' ? config('app.key') : $chave]);
    $this->app->detectEnvironment(fn (): string => 'production');

    Log::spy();

    (new UploadsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem): bool => str_contains($mensagem, $trecho) && str_contains($mensagem, 'RECUSADO') && ! str_contains($mensagem, (string) config('app.key')))->once();
})->with([
    'ausente' => [null, 'não está configurada'],
    'inválida' => ['base64:xx', 'formato inválido'],
    'igual à APP_KEY' => ['APP_KEY', 'igual à APP_KEY'],
]);

it('em produção COM chave válida, nenhum aviso de cifra no boot; fora de produção, nunca', function (): void {
    config(['uploads.confidential.key' => chaveConfidencialDeTeste()]);
    $this->app->detectEnvironment(fn (): string => 'production');

    Log::spy();
    (new UploadsServiceProvider($this->app))->boot();
    Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($mensagem): bool => is_string($mensagem) && str_contains($mensagem, 'UPLOADS_ENCRYPTION_KEY'))]);

    config(['uploads.confidential.key' => null]);
    $this->app->detectEnvironment(fn (): string => 'local');

    Log::spy();
    (new UploadsServiceProvider($this->app))->boot();
    Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($mensagem): bool => is_string($mensagem) && str_contains($mensagem, 'UPLOADS_ENCRYPTION_KEY'))]);
});

it('SEM A EXTENSÃO sodium: o upload confidencial é recusado com a mensagem que diz isso, e o boot de produção avisa para instalar a ext-sodium', function (): void {
    Keyring::$sodiumOverride = false;

    try {
        expect(Keyring::fromConfig()->status())->toBe(Keyring::SODIUM_MISSING);

        try {
            enviaConfidencial(pdfConfidencial());
            $this->fail('O upload confidencial deveria ter sido recusado.');
        } catch (ConfidentialStorageUnavailableException $exception) {
            expect($exception->reason)->toBe(Keyring::SODIUM_MISSING)
                ->and($exception->getMessage())->toBe(__('uploads.confidential.unavailable_sodium'))
                ->and($exception->getMessage())->toContain('ext-sodium');
        }

        expect(Storage::disk('local')->allFiles())->toBe([]);

        $this->app->detectEnvironment(fn (): string => 'production');
        Log::spy();

        (new UploadsServiceProvider($this->app))->boot();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'ext-sodium') && str_contains($mensagem, 'RECUSADO'))->once();

        $this->artisan('uploads:encryption-key --show')->expectsOutputToContain('ext-sodium')->assertFailed();
    } finally {
        Keyring::$sodiumOverride = null;
    }
});
