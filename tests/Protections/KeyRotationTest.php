<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Confidential\Exceptions\UndecryptableUploadException;
use Twstec\Kit\Uploads\Confidential\Keyring;
use Twstec\Kit\Uploads\Console\ReencryptConfidentialUploads;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;

// =============================================================================
// ROTAÇÃO DA CHAVE dos confidenciais, sem indisponibilidade (aplicação limpa):
//
// - trocar a chave (a antiga vira anterior) mantém os arquivos antigos
//   legíveis ANTES de recifrar;
// - `uploads:reencrypt` recifra com a atual (caminho novo, versão nova no
//   registro e no cabeçalho), apaga o objeto antigo e é idempotente e
//   retomável (o que falha fica para a próxima rodada, o resto anda);
// - depois, a chave antiga pode sair: tudo continua legível;
// - `uploads:encryption-key` gera, recusa sobrescrever e troca (--rotate).
// =============================================================================

function enviaParaRotacao(string $marca): Upload
{
    $pessoa = User::fixture(['email_verified_at' => now()]);

    return Accounts::actingAs(
        app(AccountService::class)->personalAccountOf($pessoa),
        fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(pdfConfidencial($marca), 'doc.pdf'), classification: UploadClassification::Confidential),
        $pessoa,
    );
}

function relido(Upload $upload): Upload
{
    return Accounts::asSystem('teste: relê', fn (): Upload => Upload::query()->whereKey($upload->getKey())->firstOrFail());
}

it('trocar a chave mantém os antigos legíveis; o reencrypt passa todos para a nova; depois a antiga pode sair', function (): void {
    $antiga = ligarConfidenciais();
    $idAntigo = Keyring::fromConfig()->current()->id;

    $a = enviaParaRotacao('PRIMEIRO');
    $b = enviaParaRotacao('SEGUNDO');
    $originalA = conteudoDecifrado($a);
    $caminhoAntigoA = (string) $a->path;

    // A troca: a nova é a atual, a antiga continua configurada como anterior.
    $nova = ligarConfidenciais(null, [$antiga]);
    $idNovo = Keyring::fromConfig()->current()->id;

    expect($idNovo)->not->toBe($idAntigo)
        // Sem indisponibilidade: antes de recifrar, o antigo continua abrindo.
        ->and(conteudoDecifrado($a))->toBe($originalA);

    // Um arquivo novo já nasce na chave nova.
    $c = enviaParaRotacao('TERCEIRO');
    expect($c->encryption_key_id)->toBe($idNovo);

    $this->artisan('uploads:reencrypt')
        ->expectsOutputToContain('Recifrados: 2. Falharam: 0.')
        ->expectsOutputToContain('podem sair de UPLOADS_ENCRYPTION_PREVIOUS_KEYS')
        ->assertSuccessful();

    $a = relido($a);
    $b = relido($b);

    expect($a->encryption_key_id)->toBe($idNovo)
        ->and($b->encryption_key_id)->toBe($idNovo)
        ->and($a->path)->not->toBe($caminhoAntigoA)
        ->and($a->path)->toEndWith('.pdf.enc')
        ->and(Storage::disk('local')->exists($caminhoAntigoA))->toBeFalse()
        ->and(bin2hex(substr(objetoCru($a), 9, 8)))->toBe($idNovo)
        ->and(objetoCru($a))->not->toContain('PRIMEIRO')
        ->and(conteudoDecifrado($a))->toBe($originalA);

    // A antiga sai da configuração: tudo continua legível.
    ligarConfidenciais($nova, []);

    expect(conteudoDecifrado($a))->toBe($originalA)
        ->and(conteudoDecifrado(relido($b)))->toContain('SEGUNDO')
        ->and(conteudoDecifrado(relido($c)))->toContain('TERCEIRO');

    $linha = AuditEvent::query()->where('action', ReencryptConfidentialUploads::AUDIT_ACTION)->sole();

    expect($linha->changes['reencrypted']['after'])->toBe(2)
        ->and($linha->changes['key_id']['after'])->toBe($idNovo)
        ->and(json_encode($linha->toArray()))->not->toContain(substr($nova, 7))
        ->and(json_encode($linha->toArray()))->not->toContain(substr($antiga, 7));
});

it('sem a chave anterior configurada, o arquivo antigo NÃO abre — por isso a troca guarda a antiga', function (): void {
    ligarConfidenciais();
    $a = enviaParaRotacao('SEM-ANTERIOR');

    ligarConfidenciais();

    expect(fn () => conteudoDecifrado($a))->toThrow(UndecryptableUploadException::class, 'unknown_key');
});

it('IDEMPOTENTE: a segunda rodada não faz nada; RETOMÁVEL: o que falha fica, o resto anda, e a rodada seguinte termina', function (): void {
    $antiga = ligarConfidenciais();

    $bom = enviaParaRotacao('BOM');
    $estragado = enviaParaRotacao('ESTRAGADO');
    $objetoBomDoEstragado = objetoCru($estragado);

    // Um objeto corrompido no armazenamento (falha no meio da rodada).
    Storage::disk('local')->put((string) $estragado->path, substr($objetoBomDoEstragado, 0, -5));

    ligarConfidenciais(null, [$antiga]);
    $idNovo = Keyring::fromConfig()->current()->id;

    $this->artisan('uploads:reencrypt')
        ->expectsOutputToContain('Recifrados: 1. Falharam: 1.')
        ->assertFailed();

    expect(relido($bom)->encryption_key_id)->toBe($idNovo)
        // O que falhou ficou exatamente como estava: na chave antiga, no
        // mesmo caminho.
        ->and(relido($estragado)->encryption_key_id)->not->toBe($idNovo)
        ->and(relido($estragado)->path)->toBe($estragado->path);

    // O objeto volta inteiro (restaurado do backup, por exemplo): a rodada
    // seguinte retoma só o que falta.
    Storage::disk('local')->put((string) $estragado->path, $objetoBomDoEstragado);

    $this->artisan('uploads:reencrypt')->expectsOutputToContain('Recifrados: 1. Falharam: 0.')->assertSuccessful();
    $this->artisan('uploads:reencrypt')->expectsOutputToContain('Recifrados: 0. Falharam: 0.')->assertSuccessful();

    expect(relido($estragado)->encryption_key_id)->toBe($idNovo)
        ->and(conteudoDecifrado(relido($estragado)))->toContain('ESTRAGADO')
        // Nenhum objeto sobrando: só os dois atuais.
        ->and(Storage::disk('local')->allFiles('uploads'))->toHaveCount(2);
});

it('--dry-run só conta; sem chave atual o comando recusa', function (): void {
    $antiga = ligarConfidenciais();
    enviaParaRotacao('DRY');
    ligarConfidenciais(null, [$antiga]);

    $this->artisan('uploads:reencrypt --dry-run')->expectsOutputToContain('[dry-run] 1 upload(s)')->assertSuccessful();

    config(['uploads.confidential.key' => null]);

    $this->artisan('uploads:reencrypt')->assertFailed();
});

it('uploads:encryption-key: gera no .env, recusa sobrescrever, troca com --rotate (a atual vira anterior) e --show não grava', function (): void {
    $pasta = sys_get_temp_dir().'/kit-uploads-env-'.uniqid();
    mkdir($pasta);
    file_put_contents($pasta.'/.env', "APP_NAME=Teste\nUPLOADS_ENCRYPTION_KEY=\n");
    $this->app->useEnvironmentPath($pasta);
    config(['uploads.confidential.key' => null, 'uploads.confidential.previous_keys' => []]);

    try {
        $this->artisan('uploads:encryption-key')->assertSuccessful();

        preg_match('/^UPLOADS_ENCRYPTION_KEY=(.*)$/m', (string) file_get_contents($pasta.'/.env'), $gravada);
        $primeira = $gravada[1] ?? '';

        expect(Keyring::decode($primeira))->not->toBeNull()
            ->and(config('uploads.confidential.key'))->toBe($primeira);

        // Já com chave: sobrescrever deixaria os arquivos sem leitura.
        $this->artisan('uploads:encryption-key')->assertFailed();
        expect((string) file_get_contents($pasta.'/.env'))->toContain('UPLOADS_ENCRYPTION_KEY='.$primeira);

        $this->artisan('uploads:encryption-key --rotate')->assertSuccessful();

        $env = (string) file_get_contents($pasta.'/.env');
        preg_match('/^UPLOADS_ENCRYPTION_KEY=(.*)$/m', $env, $nova);

        expect($nova[1])->not->toBe($primeira)
            ->and(Keyring::decode($nova[1]))->not->toBeNull()
            ->and($env)->toContain('UPLOADS_ENCRYPTION_PREVIOUS_KEYS='.$primeira)
            ->and(substr_count($env, 'UPLOADS_ENCRYPTION_KEY='))->toBe(1);

        $antes = (string) file_get_contents($pasta.'/.env');
        $this->artisan('uploads:encryption-key --show')->expectsOutputToContain('base64:')->assertSuccessful();
        expect((string) file_get_contents($pasta.'/.env'))->toBe($antes);
    } finally {
        @unlink($pasta.'/.env');
        @rmdir($pasta);
    }
});
