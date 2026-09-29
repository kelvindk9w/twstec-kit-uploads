<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Testbench;
use Spatie\Backup\BackupServiceProvider;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Auth\Providers\AuthServiceProvider;
use Twstec\Kit\Foundation\Audit\Providers\AuditServiceProvider;
use Twstec\Kit\Foundation\FoundationServiceProvider;
use Twstec\Kit\Foundation\Mail\Providers\MailServiceProvider;
use Twstec\Kit\Foundation\Settings\Providers\SettingsServiceProvider;
use Twstec\Kit\Uploads\Tests\Fixtures\User;
use Twstec\Kit\Uploads\UploadsServiceProvider;

/**
 * Aplicação Laravel LIMPA — o esqueleto do Testbench, os pacotes foundation,
 * auth e accounts (dos quais este depende) e este pacote. Nada do starter:
 * nenhuma view, nenhum provider do aplicativo, nenhuma linha de
 * bootstrap/app.php além da padrão, nenhum config/filesystems.php. A rota
 * /api/v1/uploads é a que o PRÓPRIO pacote registra, e o disco é o `local`
 * do Laravel (só com a raiz apontada para uma pasta descartável); se uma
 * proteção só funcionasse porque o starter lembrou de ligá-la, a suíte
 * reprovaria.
 */
#[WithMigration] // a tabela `users` do esqueleto Laravel; o resto vem das migrations dos pacotes
abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    /**
     * Providers do pacote — os mesmos que a descoberta automática instala
     * (composer.json → extra.laravel; um teste confere).
     *
     * @var list<class-string>
     */
    public const PACKAGE_PROVIDERS = [
        UploadsServiceProvider::class,
    ];

    /**
     * Configuração aplicada ANTES de os providers subirem (como num processo
     * de verdade) — ver bootWith().
     *
     * @var array<string, mixed>
     */
    public static array $scenario = [];

    /**
     * Pasta descartável que faz as vezes de storage/app/private.
     */
    public static ?string $disk = null;

    protected function getPackageProviders($app): array
    {
        // Numa aplicação, a descoberta segue a ordem do vendor: spatie,
        // twstec/kit-accounts, twstec/kit-auth, twstec/kit-foundation,
        // twstec/kit-uploads (e os providers dos módulos de auditoria, e-mail
        // e configurações da base).
        return [
            BackupServiceProvider::class,
            AccountsServiceProvider::class,
            AuthServiceProvider::class,
            FoundationServiceProvider::class,
            AuditServiceProvider::class,
            MailServiceProvider::class,
            SettingsServiceProvider::class,
            ...self::PACKAGE_PROVIDERS,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);

        // O disco `local` do Laravel, como vem — só a raiz vai para uma pasta
        // descartável, para a suíte não escrever dentro do vendor.
        self::$disk ??= sys_get_temp_dir().'/kit-uploads-disk-'.uniqid();
        $app['config']->set('filesystems.disks.local.root', self::$disk);

        foreach (static::$scenario as $key => $value) {
            $app['config']->set($key, $value);
        }

        // O Testbench fixa o ambiente em `testing`; um cenário que declara
        // `app.env` sobe nele de verdade.
        if (isset(static::$scenario['app.env'])) {
            $environment = (string) static::$scenario['app.env'];
            $app->detectEnvironment(static fn (): string => $environment);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        // A coluna `users.avatar_upload_id` é do APLICATIVO (a migration de
        // usuários dele); aqui, a de uma aplicação que usa a foto de perfil.
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function tearDown(): void
    {
        if (self::$disk !== null) {
            (new Filesystem)->deleteDirectory(self::$disk);
            self::$disk = null;
        }

        parent::tearDown();
    }

    /**
     * Sobe uma aplicação nova com a configuração dada já valendo no boot.
     *
     * @param  array<string, mixed>  $config
     */
    protected function bootWith(array $config): void
    {
        static::$scenario = $config;

        try {
            $this->refreshApplication();
            // A aplicação nova vem com um banco em memória novo e vazio: migra
            // de novo (o esqueleto Laravel, as migrations dos pacotes e a
            // coluna do avatar).
            $this->loadLaravelMigrations();
            $this->loadMigrationsFrom(__DIR__.'/database/migrations');
            $this->artisan('migrate');
        } finally {
            static::$scenario = [];
        }
    }

    /**
     * Conta ativa, com e-mail confirmado (a API recusa dono não verificado).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function owner(array $attributes = []): User
    {
        return User::fixture(['email_verified_at' => now(), ...$attributes]);
    }

    /**
     * Chave criada pelo serviço do twstec/kit-accounts (como o painel e a API
     * criam).
     *
     * @param  array<string, mixed>  $data
     * @return array{api_key: ApiKey, secret_key: string}
     */
    protected function keyFor(User $owner, array $data = []): array
    {
        // A chave é da CONTA pessoal da pessoa (contas com membros).
        return Accounts::actingAs(
            app(AccountService::class)->personalAccountOf($owner) ?? throw new \LogicException('Pessoa sem conta pessoal.'),
            fn (): array => app(ApiKeyService::class)->create($owner, ['name' => 'Integração', ...$data]),
            $owner,
        );
    }

    /**
     * Roda o callback na CONTA PESSOAL da pessoa (a conta atual), com ela
     * agindo — o contexto que a web e a API dão ao serviço de upload. Sem
     * pessoa, uma nova.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function inAccountOf(?User $person, \Closure $callback): mixed
    {
        $person ??= $this->owner();

        return Accounts::actingAs(
            app(AccountService::class)->personalAccountOf($person) ?? throw new \LogicException('Pessoa sem conta pessoal.'),
            $callback,
            $person,
        );
    }

    /**
     * Cabeçalhos do par de credenciais.
     *
     * @return array<string, string>
     */
    protected function credentials(ApiKey $key, string $secret): array
    {
        return [
            'X-Api-Key' => (string) $key->public_key,
            'Authorization' => 'Bearer '.$secret,
            'Accept' => 'application/json',
        ];
    }

    /**
     * POST /api/v1/uploads com uma chave nova do dono.
     *
     * @param  list<string>  $scopes
     */
    protected function postUpload(User $owner, UploadedFile $file, array $scopes = ['*:*']): TestResponse
    {
        ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner, ['scopes' => $scopes]);

        return $this->post('/api/v1/uploads', ['file' => $file], $this->credentials($key, $secret));
    }
}
