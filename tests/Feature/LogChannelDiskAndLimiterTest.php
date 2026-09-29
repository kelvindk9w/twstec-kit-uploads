<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;

// =============================================================================
// O CANAL DE LOG, A CONFIGURAÇÃO, O DISCO E O LIMITADOR QUE O PACOTE USA
// EXISTEM NUMA APLICAÇÃO LIMPA — e os da aplicação vencem.
//
//   - log: `upload.stored` e `upload.rejected` vão para o canal PADRÃO da
//     aplicação (o pacote não exige canal próprio: o padrão existe em toda
//     aplicação Laravel); o canal padrão que a aplicação escolher recebe;
//   - configuração `uploads`: vem deste pacote (ServiceProviderTest);
//   - disco: o `local` que toda aplicação Laravel tem, com a entrega assinada
//     ligada pelo pacote; o disco que a aplicação escolher em `uploads.disk`
//     vence;
//   - limitador: a rota de upload conta no `throttle:api` do grupo `api`
//     (posto pelo twstec/kit-accounts, com o limitador `api` do foundation);
//     um RateLimiter::for('api') da aplicação o substitui.
// =============================================================================

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kit-uploads-canal-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('os eventos do upload vão para o canal padrão que a aplicação escolher — aceito e recusado', function (): void {
    $dir = sys_get_temp_dir().'/kit-uploads-canal-'.uniqid();

    $this->bootWith([
        'logging.channels.meu' => ['driver' => 'single', 'path' => $dir.'/meu.log', 'level' => 'debug'],
        'logging.default' => 'meu',
    ]);

    $this->inAccountOf(null, function (): void {
        app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf'));

        try {
            app(SecureUploadService::class)->handle(fixtureArquivoEnviado('texto', 'foto.png'));
        } catch (Throwable) {
            // recusado — o que importa aqui é a linha no log
        }
    });

    $log = (string) file_get_contents($dir.'/meu.log');

    expect($log)->toContain('upload.stored')
        ->and($log)->toContain('upload.rejected')
        ->and($log)->toContain('"reason":"mime_not_allowed"')
        // Nada do conteúdo do arquivo no log.
        ->and($log)->not->toContain('texto');
});

it('o disco padrão é o local de toda aplicação Laravel, e o disco escolhido pela aplicação vence', function (): void {
    $padrao = $this->inAccountOf(null, fn () => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf')));

    expect($padrao->disk)->toBe('local');
    Storage::disk('local')->assertExists($padrao->path);

    $raiz = sys_get_temp_dir().'/kit-uploads-canal-disco-'.uniqid();

    $this->bootWith([
        'uploads.disk' => 'arquivos',
        'filesystems.disks.arquivos' => ['driver' => 'local', 'root' => $raiz, 'serve' => true, 'url' => '/arquivos'],
    ]);

    [$escolhido, $url] = $this->inAccountOf(null, function (): array {
        $upload = app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf'));

        return [$upload, $upload->url()];
    });

    expect($escolhido->disk)->toBe('arquivos')
        ->and(is_file($raiz.'/'.$escolhido->path))->toBeTrue()
        ->and($url)->toContain('/arquivos/'.$escolhido->path.'?');

    $this->get($url)->assertOk();
    $this->get('/arquivos/'.$escolhido->path)->assertForbidden();
});

it('a rota de upload conta no limitador api, e um limitador api da aplicação o substitui', function (): void {
    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);

    // A aplicação registra o dela (num provider que sobe depois dos pacotes).
    RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(1)->by('da-aplicacao'));

    $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'a.pdf')], $this->credentials($key, $secret))->assertCreated();
    $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'b.pdf')], $this->credentials($key, $secret))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests');

    expect($this->inAccountOf($owner, fn (): int => Upload::query()->count()))->toBe(1);
});
