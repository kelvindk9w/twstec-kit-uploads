<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Http;

use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\Http\ApiRoutes;
use Twstec\Kit\Uploads\Http\Controllers\UploadController;

/**
 * A rota de upload da API v1: POST /api/v1/uploads (escopo `uploads:create`).
 *
 * O pacote a registra sozinho (routes/api.php), no MESMO grupo das rotas v1
 * do twstec/kit-accounts: prefixo, middleware e nomes vêm de
 * `uploads.api.routes` e, quando nulos (o padrão), de `api_keys.api.routes`.
 * Uma aplicação que prefira registrar ela mesma desliga o registro automático
 * (`uploads.api.routes.enabled = false`, UPLOADS_API_ROUTES=false) e chama
 * `UploadRoutes::register()` onde quiser — por exemplo, dentro do próprio
 * routes/api.php, que o Laravel já monta com o prefixo `api` e o grupo `api`:
 *
 *     UploadRoutes::register(prefix: 'v1', middleware: []);
 *
 * Em qualquer caso a AUTENTICAÇÃO POR CHAVE (`resolve.tenant`, do
 * twstec/kit-accounts) entra no grupo — ela não é opção de quem registra —, e
 * a rota traz o próprio escopo (`scope:uploads:create`). O registro fica
 * na conta da chave (ver SecureUploadService e Models\Upload).
 */
final class UploadRoutes
{
    /**
     * Registra a rota de upload da API v1.
     *
     * @param  string|null  $prefix  Prefixo do grupo; null = `uploads.api.routes.prefix`, e depois o das rotas v1 (`api/v1`).
     * @param  list<string>|null  $middleware  Middleware antes da autenticação; null = `uploads.api.routes.middleware`, e depois o das rotas v1 (`['api']`).
     * @param  string|null  $name  Prefixo do nome; null = `uploads.api.routes.name`, e depois o das rotas v1 (`api.v1.`).
     */
    public static function register(?string $prefix = null, ?array $middleware = null, ?string $name = null): void
    {
        $prefix ??= (string) (config('uploads.api.routes.prefix') ?? config('api_keys.api.routes.prefix', 'api/v1'));
        $middleware ??= (array) (config('uploads.api.routes.middleware') ?? config('api_keys.api.routes.middleware', ['api']));
        $name ??= (string) (config('uploads.api.routes.name') ?? config('api_keys.api.routes.name', 'api.v1.'));

        Route::prefix($prefix)
            ->middleware([...$middleware, ApiRoutes::AUTHENTICATION])
            ->name($name)
            ->group(static function (): void {
                // Função global única: validação de formulário (Form Request)
                // → validação de segurança do arquivo (magic bytes, polyglot,
                // PDF com script) → upload.
                Route::post('uploads', [UploadController::class, 'store'])
                    ->middleware('scope:uploads:create')
                    ->name('uploads.store');
            });
    }
}
