<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Uploads\Http\UploadRoutes;
use Twstec\Kit\Uploads\Tests\TestCase;
use Twstec\Kit\Uploads\UploadsServiceProvider;

/**
 * As rotas de upload registradas, como "MÉTODO uri nome => middleware da rota".
 *
 * @return list<string>
 */
function uploadsApiRoutes(string $namePrefix = 'api.v1.'): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), $namePrefix) && str_contains($route->uri(), 'uploads'))
        ->map(fn (Route $route): string => implode('|', $route->methods()).' '.$route->uri().' '.$route->getName().' => '.implode(',', $route->middleware()))
        ->values()
        ->all();
}

it('os providers da suíte são os da descoberta automática', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe(TestCase::PACKAGE_PROVIDERS);
});

it('traz a configuração padrão de config(uploads), com as chaves novas', function (): void {
    expect(config('uploads.disk'))->toBe('local')
        ->and(config('uploads.directory'))->toBe('uploads')
        ->and(config('uploads.temporary_url_minutes'))->toBe(15)
        ->and(config('uploads.allowed_types'))->toBe(['image', 'pdf'])
        ->and(config('uploads.types.image.mimes'))->toBe(['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'])
        ->and(config('uploads.types.image.max_kb'))->toBe(5120)
        ->and(config('uploads.types.image.max_pixels'))->toBe(25000000)
        ->and(config('uploads.types.pdf'))->toBe(['mimes' => ['application/pdf' => 'pdf'], 'max_kb' => 10240])
        ->and(config('uploads.protections'))->toBeTrue()
        ->and(config('uploads.api.routes'))->toBe(['enabled' => true, 'prefix' => null, 'middleware' => null, 'name' => null]);
});

it('a configuração da aplicação vence a do pacote, chave de primeiro nível a chave', function (): void {
    $this->bootWith(['uploads.temporary_url_minutes' => 3, 'uploads.types' => ['pdf' => ['mimes' => ['application/pdf' => 'pdf'], 'max_kb' => 1]]]);

    expect(config('uploads.temporary_url_minutes'))->toBe(3)
        ->and(config('uploads.types'))->toBe(['pdf' => ['mimes' => ['application/pdf' => 'pdf'], 'max_kb' => 1]])
        // O que a aplicação não definiu vem do pacote.
        ->and(config('uploads.directory'))->toBe('uploads');
});

it('roda a migration com o mesmo nome de arquivo que tinha no aplicativo', function (): void {
    $arquivos = array_map('basename', glob(dirname(__DIR__, 2).'/database/migrations/*.php'));

    expect($arquivos)->toBe(['2026_08_20_300000_create_uploads_table.php', '2026_09_28_000001_move_uploads_to_accounts.php'])
        ->and(app('migrator')->paths())->toContain(dirname(__DIR__, 2).'/database/migrations')
        ->and(Schema::hasTable('uploads'))->toBeTrue()
        ->and(Schema::hasColumns('uploads', ['uuid', 'codigo_publico', 'account_id', 'created_by', 'personal', 'orphaned_at', 'disk', 'path', 'original_name', 'mime', 'size', 'sha256', 'status']))->toBeTrue();
});

it('registra POST /api/v1/uploads no grupo das rotas v1, com o nome e o middleware de rota de sempre', function (): void {
    expect(uploadsApiRoutes())->toBe([
        'POST api/v1/uploads api.v1.uploads.store => api,resolve.tenant,scope:uploads:create',
    ]);
});

it('segue o prefixo, o grupo e os nomes das rotas v1 do accounts quando não tem os próprios', function (): void {
    $this->bootWith(['api_keys.api.routes.prefix' => 'api/integracoes/v1', 'api_keys.api.routes.name' => 'integracoes.']);

    expect(uploadsApiRoutes('integracoes.'))->toBe([
        'POST api/integracoes/v1/uploads integracoes.uploads.store => api,resolve.tenant,scope:uploads:create',
    ]);
});

it('com o registro automático desligado, a aplicação registra a rota onde quiser — e a autenticação por chave vem junto', function (): void {
    $this->bootWith(['uploads.api.routes.enabled' => false]);

    expect(uploadsApiRoutes())->toBe([]);

    UploadRoutes::register(prefix: 'api/arquivos', middleware: ['api'], name: 'arquivos.');
    app('router')->getRoutes()->refreshNameLookups();

    expect(uploadsApiRoutes('arquivos.'))->toBe([
        'POST api/arquivos/uploads arquivos.uploads.store => api,resolve.tenant,scope:uploads:create',
    ]);

    // E a rota responde com a proteção (401 no envelope da API).
    $this->postJson('/api/arquivos/uploads')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
});

it('liga a entrega assinada no disco local de uploads, diga a aplicação o que disser sobre ela', function (array $disco): void {
    // Esqueleto antigo (sem a chave `serve`) ou o do Testbench (`serve` false).
    $this->bootWith(['filesystems.disks.local' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/kit-uploads-sem-serve', 'throw' => false, ...$disco]]);

    expect(config('filesystems.disks.local.serve'))->toBeTrue()
        ->and(app('router')->getRoutes()->getByName('storage.local'))->not->toBeNull();
})->with([
    'sem a chave' => [[]],
    'desligada' => [['serve' => false]],
]);

it('não liga a entrega quando outro disco já responde no mesmo endereço (derrubaria o boot) — e avisa no log', function (): void {
    $this->bootWith([
        'filesystems.disks.local' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/kit-uploads-a'],
        'filesystems.disks.outro' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/kit-uploads-b', 'serve' => true],
    ]);

    expect(config('filesystems.disks.local'))->not->toHaveKey('serve');

    Log::spy();

    (new UploadsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'não tem a entrega assinada'))->atLeast()->once();
});

it('o disco escolhido pela aplicação vence: o pacote não mexe num disco S3 nem num disco local que já declara a entrega', function (): void {
    $this->bootWith([
        'uploads.disk' => 'arquivos',
        'filesystems.disks.arquivos' => ['driver' => 's3', 'bucket' => 'x', 'region' => 'auto'],
    ]);

    expect(config('filesystems.disks.arquivos'))->toBe(['driver' => 's3', 'bucket' => 'x', 'region' => 'auto']);
});
