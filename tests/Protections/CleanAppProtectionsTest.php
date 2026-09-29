<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Http\Controllers\AvatarController;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Rules\SafeFile;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\UploadsServiceProvider;

// =============================================================================
// AS PROTEÇÕES DO UPLOAD VÊM DO PACOTE — numa aplicação Laravel limpa
// (Testbench), sem nada do starter: nenhum config/filesystems.php, nenhuma
// rota declarada pelo teste (o POST /api/v1/uploads é o que o pacote
// registra; o do avatar, que é rota WEB do aplicativo, o teste declara como o
// aplicativo declararia), nenhum provider do aplicativo, nenhuma variável do
// .env do starter (tests/bootstrap.php). Se uma proteção daqui só funcionasse
// porque o starter lembrou de ligá-la, este arquivo reprovaria.
// =============================================================================

/**
 * Texto que o PACOTE traz para a chave (no idioma da suíte, en), lido do
 * arquivo — não do tradutor —, para provar que a mensagem é a do pacote.
 *
 * @param  array<string, string|int>  $replace
 */
function uploadsMessage(string $key, array $replace = []): string
{
    [$group, $item] = explode('.', $key, 2);
    $text = (string) Arr::get(require dirname(__DIR__, 2)."/lang/en/{$group}.php", $item);

    foreach ($replace as $name => $value) {
        $text = str_replace(':'.$name, (string) $value, $text);
    }

    return $text;
}

/**
 * Nada foi gravado: nem registro, nem arquivo no disco.
 */
function assertNothingStored(): void
{
    // Todas as contas (modo sistema do teste).
    expect(Accounts::asSystem('teste: nada gravado', fn (): int => Upload::query()->count()))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
}

/**
 * O conteúdo entregue por uma resposta de arquivo.
 */
function servedContent(TestResponse $response): string
{
    return $response->baseResponse instanceof BinaryFileResponse
        ? (string) file_get_contents($response->baseResponse->getFile()->getPathname())
        : (string) $response->streamedContent();
}

/**
 * Executa o serviço e devolve a recusa.
 */
function expectRejection(UploadedFile $file): UploadRejectedException
{
    try {
        // Na conta pessoal de uma pessoa nova (o serviço exige conta atual).
        test()->inAccountOf(null, fn () => app(SecureUploadService::class)->handle($file));
    } catch (UploadRejectedException $exception) {
        return $exception;
    }

    test()->fail('O upload deveria ter sido recusado.');
}

// --- Validação pelo CONTEÚDO -------------------------------------------------

it('arquivo com extensão de imagem e conteúdo falso: recusado, com a mensagem do pacote, e nada gravado', function (): void {
    $owner = $this->owner();

    // Texto com nome .png: o serviço recusa pelo MIME real (mime_not_allowed).
    expect(expectRejection(fixtureArquivoEnviado('Relatorio de vendas do mes.', 'foto.png'))->reason)->toBe('mime_not_allowed');

    // Imagem "de verdade" com código PHP colado (polyglot): passa pela regra de
    // formulário (é um PNG) e é recusada pelo pacote.
    $this->postUpload($owner, fixtureArquivoEnviado(fixtureBytesPng()."\n<?php system(\$_GET['c']);", 'foto.png'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.errors.file.0', uploadsMessage('uploads.rejected.embedded_script'));

    // Conteúdo PNG real com nome .pdf: extensão divergente do conteúdo.
    $this->postUpload($owner, fixtureArquivoEnviado(fixtureBytesPng(), 'boleto.pdf'))
        ->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', uploadsMessage('uploads.rejected.extension_mismatch'));

    assertNothingStored();
});

it('a regra SafeFile (formulários que não são Form Request) recusa o conteúdo falso com a mesma lei', function (): void {
    $falso = Validator::make(['foto' => fixtureArquivoEnviado('<?php echo 1;', 'foto.png')], ['foto' => [new SafeFile(['image'])]]);
    $legitimo = Validator::make(['foto' => fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png')], ['foto' => [new SafeFile(['image'])]]);

    expect($falso->fails())->toBeTrue()
        ->and($legitimo->passes())->toBeTrue();
});

it('executável com extensão de documento e PDF com JavaScript: recusados', function (): void {
    expect(expectRejection(fixtureArquivoEnviado(fixtureBytesElf(), 'boleto.pdf'))->reason)->toBe('executable');

    $this->postUpload($this->owner(), fixtureArquivoEnviado(fixtureBytesPdfComJavaScript(), 'documento.pdf'))
        ->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', uploadsMessage('uploads.rejected.pdf_auto_action'));

    assertNothingStored();
});

it('arquivo acima do limite do tipo: recusado, com o limite na mensagem, e nada gravado', function (): void {
    config(['uploads.types.pdf.max_kb' => 1]);

    // PDF válido inflado com comentários além de 1 KB. O corte grosseiro do
    // formulário usa o maior limite da config (o da imagem) e deixa passar; o
    // limite fino POR TIPO é do pacote.
    $this->postUpload($this->owner(), fixtureArquivoEnviado(fixtureBytesPdf()."\n%".str_repeat('A', 2048), 'grande.pdf'))
        ->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', uploadsMessage('uploads.rejected.too_large', ['max' => 1]));

    assertNothingStored();
});

it('imagem acima do teto de pixels (decompression bomb): recusada antes de decodificar', function (): void {
    config(['uploads.types.image.max_pixels' => 1000]);

    expect(expectRejection(fixtureArquivoEnviado(fixtureBytesPngDe(100, 100), 'foto.png'))->reason)->toBe('image_too_many_pixels');
});

it('a imagem aceita é reprocessada: o que foi anexado a ela não chega ao disco, e o nome vem do conteúdo', function (): void {
    $owner = $this->owner();

    $response = $this->postUpload($owner, fixtureArquivoEnviado(fixtureBytesPng().'EVILPAYLOAD', 'minha foto.png'))
        ->assertCreated()
        ->assertJsonPath('message', uploadsMessage('uploads.stored'))
        ->assertJsonPath('data.mime', 'image/png');

    $path = (string) $response->json('data.path');
    $stored = (string) Storage::disk('local')->get($path);

    expect(basename($path))->toMatch('/^[0-9a-f-]{36}\.png$/')
        ->and($stored)->not->toContain('EVILPAYLOAD')
        ->and(hash('sha256', $stored))->toBe($response->json('data.sha256'));
});

// --- Entrega por URL ASSINADA ------------------------------------------------

it('a URL devolvida é assinada e com validade: entrega o arquivo; sem assinatura, adulterada ou vencida é recusada', function (): void {
    $response = $this->postUpload($this->owner(), fixtureArquivoEnviado(fixtureBytesPdf(), 'contrato.pdf'))->assertCreated();

    $url = (string) $response->json('data.url');

    expect($url)->toContain('/storage/uploads/')
        ->and($url)->toContain('expires=')
        ->and($url)->toContain('signature=');

    $entregue = $this->get($url)->assertOk();

    expect(servedContent($entregue))->toBe(fixtureBytesPdf());

    // Sem assinatura.
    $this->get(strtok($url, '?'))->assertForbidden();

    // Assinatura adulterada.
    $this->get((string) preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $url))->assertForbidden();

    // Vencida: a validade padrão do pacote é de 15 minutos.
    $this->travel(16)->minutes();
    $this->get($url)->assertForbidden();
});

it('a rota que entrega os arquivos é a do disco privado e não responde a nada sem assinatura', function (): void {
    $upload = $this->inAccountOf(null, fn () => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png')));

    expect(config('filesystems.disks.local.visibility', 'private'))->toBe('private')
        ->and(config('filesystems.disks.local.serve'))->toBeTrue();

    $this->get('/storage/'.$upload->path)->assertForbidden();
    $this->get('/storage/'.$upload->path.'?expires=9999999999')->assertForbidden();
});

it('upload de outro dono não é acessível: a assinatura de um arquivo não abre o de outro', function (): void {
    $ana = $this->owner();
    $bruno = $this->owner();

    $daAna = $this->postUpload($ana, fixtureArquivoEnviado(fixtureBytesPdf(), 'da-ana.pdf'))->assertCreated();
    $doBruno = $this->postUpload($bruno, fixtureArquivoEnviado(fixtureBytesPng(), 'do-bruno.png'))->assertCreated();

    // Cada registro fica com a CONTA da própria chave (a pessoal de cada um).
    $contaDe = fn ($pessoa): int => (int) app(AccountService::class)->personalAccountOf($pessoa)->getKey();
    $conta = fn (string $uuid): int => (int) Accounts::asSystem('teste: conta do upload', fn () => Upload::query()->where('uuid', $uuid)->value('account_id'));

    expect($conta((string) $daAna->json('data.uuid')))->toBe($contaDe($ana))
        ->and($conta((string) $doBruno->json('data.uuid')))->toBe($contaDe($bruno));

    // A URL da Ana, com o caminho do arquivo do Bruno no lugar do dela.
    $urlDaAna = (string) $daAna->json('data.url');
    $caminhoDoBruno = (string) $doBruno->json('data.path');
    $trocada = (string) preg_replace('#/storage/[^?]+#', '/storage/'.$caminhoDoBruno, $urlDaAna);

    expect($trocada)->not->toBe($urlDaAna);

    $this->get($trocada)->assertForbidden();

    // A própria URL continua valendo só para o próprio arquivo.
    expect(servedContent($this->get($urlDaAna)->assertOk()))->toBe(fixtureBytesPdf());
});

it('a foto de perfil de uma pessoa aponta só para o upload dela', function (): void {
    // A rota WEB do avatar é do aplicativo (o starter a declara no grupo
    // autenticado); aqui, como uma aplicação a declararia.
    Route::post('settings/avatar', [AvatarController::class, 'update'])->middleware(['web', 'auth']);

    $ana = $this->owner();
    $bruno = $this->owner();

    $this->actingAs($ana)->postJson('/settings/avatar', ['avatar' => fixtureArquivoEnviado(fixtureBytesPng(), 'ana.png')])
        ->assertCreated()
        ->assertJsonPath('message', uploadsMessage('uploads.avatar_updated'));
    $this->actingAs($bruno)->postJson('/settings/avatar', ['avatar' => fixtureArquivoEnviado(fixtureBytesPngDe(2, 2), 'bruno.png')])
        ->assertCreated();

    $ana->refresh();
    $bruno->refresh();

    // A foto é da PESSOA: upload pessoal (sem conta), enviado por ela.
    expect($ana->avatarUpload()->created_by)->toBe($ana->id)
        ->and($ana->avatarUpload()->isPersonal())->toBeTrue()
        ->and($bruno->avatarUpload()->created_by)->toBe($bruno->id)
        ->and($ana->avatarUrl())->toContain('/storage/'.$ana->avatarUpload()->path.'?')
        ->and($ana->avatarUrl())->not->toContain($bruno->avatarUpload()->path);

    // O avatar é só imagem: PDF é recusado.
    $this->actingAs($ana)->postJson('/settings/avatar', ['avatar' => fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.png')])
        ->assertUnprocessable();
});

it('web e API gravam do MESMO jeito: a conta atual e quem agiu (a foto de perfil é pessoal)', function (): void {
    // Até a F8b o dono ia em `tenant_uuid` pela API e em `user_id` pela web.
    // Agora os dois caminhos gravam a conta atual (`account_id`) e quem
    // enviou (`created_by`); a foto de perfil é da pessoa (sem conta).
    Route::post('settings/avatar', [AvatarController::class, 'update'])->middleware(['web', 'auth']);
    Route::post('teste/upload-web', fn () => response()->json([
        'uuid' => app(SecureUploadService::class)->handle(request()->file('file'))->uuid,
    ]))->middleware(['web', 'auth']);

    $pessoa = $this->owner();
    $conta = (int) app(AccountService::class)->personalAccountOf($pessoa)->getKey();

    $pelaWeb = $this->actingAs($pessoa)->postJson('/teste/upload-web', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'web.pdf')])->assertOk();
    $this->actingAs($pessoa)->postJson('/settings/avatar', ['avatar' => fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png')])->assertCreated();
    $pelaApi = $this->postUpload($pessoa, fixtureArquivoEnviado(fixtureBytesPdf(), 'api.pdf'))->assertCreated();

    [$web, $api, $foto] = Accounts::asSystem('teste: dono dos uploads', fn (): array => [
        Upload::query()->where('uuid', $pelaWeb->json('uuid'))->sole(),
        Upload::query()->where('uuid', $pelaApi->json('data.uuid'))->sole(),
        Upload::query()->whereKey($pessoa->refresh()->avatar_upload_id)->sole(),
    ]);

    expect([$web->account_id, $web->created_by, $web->personal])->toBe([$conta, $pessoa->id, false])
        ->and([$api->account_id, $api->created_by, $api->personal])->toBe([$conta, $pessoa->id, false])
        ->and([$foto->account_id, $foto->created_by, $foto->personal])->toBe([null, $pessoa->id, true]);
});

// --- A rota da API -----------------------------------------------------------

it('a rota de upload exige credencial e o escopo uploads:create', function (): void {
    $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf')], ['Accept' => 'application/json'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthorized');

    $this->postUpload($this->owner(), fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf'), scopes: ['projects:read'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    assertNothingStored();
});

// --- Disco sem a entrega declarada e opt-out -----------------------------------

it('aplicação cujo disco local não tem a entrega (sem a chave, ou desligada): o pacote a liga e a URL sai assinada', function (array $disco): void {
    $raiz = sys_get_temp_dir().'/kit-uploads-esqueleto-antigo-'.uniqid();

    $this->bootWith(['filesystems.disks.local' => ['driver' => 'local', 'root' => $raiz, 'throw' => false, ...$disco]]);

    [$upload, $url] = $this->inAccountOf(null, function (): array {
        $upload = app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf'));

        return [$upload, $upload->url()];
    });

    expect($url)->toContain('signature=');

    $this->get($url)->assertOk();
    $this->get('/storage/'.$upload->path)->assertForbidden();

    (new Filesystem)->deleteDirectory($raiz);
})->with([
    'sem a chave' => [[]],
    'desligada' => [['serve' => false]],
]);

it('opt-out explícito (UPLOADS_PROTECTIONS=false): o pacote não mexe no disco e avisa no log a cada boot', function (): void {
    $raiz = sys_get_temp_dir().'/kit-uploads-opt-out-'.uniqid();

    $this->bootWith([
        'uploads.protections' => false,
        'filesystems.disks.local' => ['driver' => 'local', 'root' => $raiz, 'throw' => false],
    ]);

    expect(config('filesystems.disks.local'))->not->toHaveKey('serve');

    Log::spy();

    (new UploadsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_starts_with($message, 'UPLOADS_PROTECTIONS=false'));

    (new Filesystem)->deleteDirectory($raiz);
});

it('disco de uploads público pela aplicação: vale o que ela declarou, com aviso no log a cada boot', function (): void {
    $this->bootWith(['filesystems.disks.local' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/kit-uploads-x', 'serve' => true, 'visibility' => 'public']]);

    expect(config('filesystems.disks.local.visibility'))->toBe('public');

    Log::spy();

    (new UploadsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'é PÚBLICO'));
});
