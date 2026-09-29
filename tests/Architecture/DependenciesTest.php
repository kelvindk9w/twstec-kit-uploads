<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// ARQUITETURA DO PACOTE uploads — conhece a base (foundation), a autenticação
// (auth), as contas e a API (accounts) e o Laravel, e só.
//
// uploads é a quarta camada do kit: depende de twstec/kit-foundation,
// twstec/kit-auth e twstec/kit-accounts; o painel de administração depende
// dela, nunca o contrário. E ela não tem telas (o formulário do perfil e o
// campo de foto do painel são do front). Este arquivo reprova o build quando:
//
// 1. qualquer arquivo do pacote (código, config, migrations, traduções, rotas)
//    nomeia classe do APLICATIVO (App\…, inclusive o model de usuário — o
//    pacote trabalha com o model configurado em auth.providers.users.model),
//    da camada de cima (admin) ou de tela (Filament, Livewire);
// 2. um `use` ou nome totalmente qualificado sai do que o pacote pode usar:
//    o próprio pacote, o foundation, o auth, o accounts, o Laravel
//    (Illuminate), o HttpFoundation do Symfony (que o Laravel usa nas
//    respostas) e o Carbon (a biblioteca de datas que o próprio Laravel usa e
//    expõe). As bibliotecas de imagem são as extensões do PHP (fileinfo e
//    GD), sem namespace;
// 3. o pacote passa a ter views (telas são do front).
//
// A leitura do PHP é por tokens: comentários e strings não contam, só nomes
// de classe de verdade (`use`, `new`, `::class`, tipos, `instanceof`…).
// =============================================================================

const UPLOADS_FORBIDDEN_PREFIXES = [
    'App\\',
    'Database\\',
    'Twstec\\Kit\\Admin\\',
    'Filament\\',
    'Livewire\\',
];

const UPLOADS_ALLOWED_ROOTS = [
    'Twstec\\Kit\\Uploads\\',
    'Twstec\\Kit\\Accounts\\',
    'Twstec\\Kit\\Auth\\',
    'Twstec\\Kit\\Foundation\\',
    'Illuminate\\',
    'Symfony\\Component\\HttpFoundation\\',
    'Carbon\\',
];

const UPLOADS_SHIPPED_DIRECTORIES = ['src', 'config', 'database', 'lang', 'routes'];

function uploadsRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Nomes de classe de um arquivo PHP, lidos dos tokens: todos os qualificados
 * (`$all`) e só os que são certamente absolutos — os dos `use` do topo e os
 * escritos com `\` na frente (`$absolute`).
 *
 * @return array{all: list<string>, absolute: list<string>}
 */
function uploadsClassNamesIn(string $contents): array
{
    $all = [];
    $absolute = [];
    $inUse = false;
    $depth = 0;

    foreach (PhpToken::tokenize($contents) as $token) {
        if ($token->text === '{') {
            $depth++;
        } elseif ($token->text === '}') {
            $depth--;
        }

        if ($token->is(T_USE) && $depth === 0) {
            $inUse = true;

            continue;
        }

        if ($inUse && $token->text === ';') {
            $inUse = false;
        }

        if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = ltrim($token->text, '\\');
        $all[$name] = true;

        if ($inUse || $token->is(T_NAME_FULLY_QUALIFIED)) {
            $absolute[$name] = true;
        }
    }

    return ['all' => array_keys($all), 'absolute' => array_keys($absolute)];
}

/**
 * @return array<string, list<string>> caminho relativo => violações
 */
function uploadsDependencyViolations(): array
{
    $violations = [];

    foreach (UPLOADS_SHIPPED_DIRECTORIES as $directory) {
        foreach ((new Finder)->files()->in(uploadsRoot().'/'.$directory)->name('*.php') as $file) {
            $path = str_replace(uploadsRoot().'/', '', $file->getRealPath());
            $names = uploadsClassNamesIn($file->getContents());

            foreach ($names['all'] as $name) {
                foreach (UPLOADS_FORBIDDEN_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $violations[$path][] = "usa {$name}";
                    }
                }
            }

            foreach ($names['absolute'] as $name) {
                $allowed = false;

                foreach (UPLOADS_ALLOWED_ROOTS as $root) {
                    $allowed = $allowed || str_starts_with($name.'\\', $root);
                }

                // Funções e constantes globais importadas não têm `\`.
                if (! $allowed && str_contains($name, '\\')) {
                    $violations[$path][] = "usa {$name} (fora de foundation + auth + accounts + Laravel)";
                }
            }
        }
    }

    ksort($violations);

    return $violations;
}

it('não nomeia nada do aplicativo, da camada de cima nem de tela', function (): void {
    $violations = [];

    foreach (uploadsDependencyViolations() as $path => $problems) {
        foreach (array_unique($problems) as $problem) {
            $violations[] = "{$path} {$problem}";
        }
    }

    expect($violations)->toBe([]);
});

it('não tem telas: nenhuma view no pacote', function (): void {
    expect(is_dir(uploadsRoot().'/resources/views'))->toBeFalse();

    $blade = iterator_to_array((new Finder)->files()->in(uploadsRoot().'/src')->name('*.blade.php'), false);

    expect($blade)->toBe([]);
});

it('declara no composer.json as dependências que usa — e só as do kit, o Laravel e as extensões de imagem', function (): void {
    $composer = json_decode((string) file_get_contents(uploadsRoot().'/composer.json'), true);

    expect(array_keys($composer['require']))->toBe([
        'php',
        'ext-fileinfo',
        'ext-gd',
        'laravel/framework',
        'twstec/kit-accounts',
        'twstec/kit-auth',
        'twstec/kit-foundation',
    ]);
});

it('a leitura por tokens pega o que deve pegar (a trava não é cega)', function (): void {
    $sample = <<<'PHP'
        <?php
        namespace Twstec\Kit\Uploads\Algo;
        use App\Models\User;
        use Twstec\Kit\Accounts\Http\ApiRoutes;
        use Livewire\Component;
        // App\Demo\Coisa em comentário não conta
        $a = new \Filament\Panel;
        $b = Http\UploadRoutes::class;
        $c = 'App\\Em\\String';
        PHP;

    $names = uploadsClassNamesIn($sample);

    expect($names['absolute'])->toBe(['App\Models\User', 'Twstec\Kit\Accounts\Http\ApiRoutes', 'Livewire\Component', 'Filament\Panel'])
        ->and($names['all'])->toContain('Http\UploadRoutes')
        ->and($names['all'])->not->toContain('App\Demo\Coisa');
});
