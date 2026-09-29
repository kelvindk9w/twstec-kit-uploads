<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Symfony\Component\Finder\Finder;
use Twstec\Kit\Uploads\Concerns\HasAvatar;
use Twstec\Kit\Uploads\Enums\UploadStatus;
use Twstec\Kit\Uploads\Http\Controllers\UploadController;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;

// Nomes antigos (App\Core\Uploads\…, da 1.x) continuam resolvendo para as
// classes do pacote — é o que protege a rota em cache da 1.x (os controllers),
// o snapshot de componente Livewire e o payload de fila com um Upload, e o
// código do projeto que ainda não trocou o `use` (a trait do avatar no model
// do usuário).

/**
 * As pastas de src/ que existiam no módulo App\Core\Uploads da 1.x (as peças
 * novas do pacote — provider, rotas, entrega assinada, apelidos — não têm nome
 * antigo).
 */
const UPLOADS_LEGACY_DIRECTORIES = ['Concerns', 'Enums', 'Exceptions', 'Http/Controllers', 'Http/Requests', 'Http/Resources', 'Models', 'Rules', 'Services'];

it('resolve o nome antigo de model, enum, serviço, controller e trait para a classe nova', function (): void {
    expect((new ReflectionClass('App\\Core\\Uploads\\Models\\Upload'))->getName())->toBe(Upload::class)
        ->and((new ReflectionClass('App\\Core\\Uploads\\Services\\SecureUploadService'))->getName())->toBe(SecureUploadService::class)
        ->and((new ReflectionClass('App\\Core\\Uploads\\Http\\Controllers\\UploadController'))->getName())->toBe(UploadController::class)
        ->and(trait_exists('App\\Core\\Uploads\\Concerns\\HasAvatar'))->toBeTrue()
        ->and((new ReflectionClass('App\\Core\\Uploads\\Concerns\\HasAvatar'))->getName())->toBe(HasAvatar::class)
        ->and(enum_exists('App\\Core\\Uploads\\Enums\\UploadStatus'))->toBeTrue()
        ->and(constant('App\\Core\\Uploads\\Enums\\UploadStatus::Stored'))->toBe(UploadStatus::Stored);
});

it('o container monta o controller pelo nome antigo (rota em cache da 1.x)', function (): void {
    expect(app()->make('App\\Core\\Uploads\\Http\\Controllers\\UploadController'))->toBeInstanceOf(UploadController::class);
});

it('a referência de model com o nome antigo (snapshot, fila) acha o registro', function (): void {
    $pessoa = $this->owner();
    $upload = $this->inAccountOf($pessoa, fn () => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf')));

    $restaurador = new class
    {
        use SerializesAndRestoresModelIdentifiers {
            getRestoredPropertyValue as public;
        }
    };

    // O job restaura na conta de quem o enfileirou (contas com membros).
    [$restaurado, $url] = $this->inAccountOf($pessoa, function () use ($restaurador, $upload): array {
        $restaurado = $restaurador->getRestoredPropertyValue(new ModelIdentifier('App\\Core\\Uploads\\Models\\Upload', $upload->getKey(), [], null));

        return [$restaurado, $restaurado->url()];
    });

    expect($restaurado)->toBeInstanceOf(Upload::class)
        ->and($restaurado->is($upload))->toBeTrue()
        // O arquivo continua localizado pelo registro (disco + caminho).
        ->and($url)->toContain('/storage/'.$upload->path);
});

it('um model de usuário da 1.x que ainda usa a trait pelo nome antigo continua com a foto de perfil', function (): void {
    $pessoa = $this->owner();
    // A foto de perfil é pessoal (da pessoa, sem conta).
    $upload = app(SecureUploadService::class)->handlePersonal(fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png'), $pessoa, directory: 'avatars', allowedTypes: ['image']);
    $pessoa->forceFill(['avatar_upload_id' => $upload->id])->save();

    $daUmX = new class extends Model
    {
        use App\Core\Uploads\Concerns\HasAvatar;

        protected $table = 'users';
    };

    $modelo = $daUmX->newQuery()->whereKey($pessoa->id)->sole();

    expect(class_uses($modelo))->toContain(HasAvatar::class)
        ->and($modelo->avatarUpload())->toBeInstanceOf(Upload::class)
        ->and($modelo->avatarUpload()->is($upload))->toBeTrue()
        ->and($modelo->avatarUrl())->toContain('/storage/'.$upload->path.'?');
});

it('não inventa apelido fora do módulo nem para o que não existe', function (): void {
    expect(class_exists('App\\Core\\Uploads\\Models\\NaoExiste'))->toBeFalse()
        ->and(class_exists('App\\Core\\Uploads\\UploadsServiceProvider'))->toBeFalse()
        ->and(class_exists('App\\Core\\Outro\\Models\\Upload'))->toBeFalse();
});

it('todo arquivo de src/ que existia na 1.x tem o nome antigo equivalente resolvível', function (): void {
    $sem = [];
    $conferidos = 0;

    foreach (UPLOADS_LEGACY_DIRECTORIES as $directory) {
        foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src/'.$directory)->name('*.php') as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $directory.'/'.$file->getRelativePathname());
            $novo = "Twstec\\Kit\\Uploads\\{$relative}";
            $antigo = "App\\Core\\Uploads\\{$relative}";
            $conferidos++;

            if (! class_exists($antigo) && ! interface_exists($antigo) && ! trait_exists($antigo) && ! enum_exists($antigo)) {
                $sem[] = $antigo;

                continue;
            }

            if ((new ReflectionClass($antigo))->getName() !== $novo) {
                $sem[] = "{$antigo} não aponta para {$novo}";
            }
        }
    }

    expect($conferidos)->toBe(12)
        ->and($sem)->toBe([]);
});
