<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

// =============================================================================
// O APLICATIVO VENCE O PACOTE NAS TRADUÇÕES — a mesma regra do foundation, do
// auth e do accounts (Twstec\Kit\Foundation\Localization\PackageTranslations),
// valendo para as traduções deste pacote.
//
// Quem usa o kit troca qualquer mensagem do upload editando o lang/ do próprio
// aplicativo: numa mesma chave vale o texto do aplicativo; o pacote só
// preenche o que o aplicativo não definiu — nos três idiomas e no idioma de
// reserva.
// =============================================================================

/**
 * Troca a pasta lang/ do aplicativo por uma temporária com estes arquivos e
 * refaz o carregador de traduções (como num boot novo).
 *
 * @param  array<string, array<string, mixed>>  $files  'pt_BR/uploads.php' => conteúdo
 */
function uploadsAppLang(array $files): string
{
    $dir = sys_get_temp_dir().'/uploads-app-lang-'.uniqid();

    foreach ($files as $relative => $contents) {
        @mkdir(dirname($dir.'/'.$relative), 0755, true);
        file_put_contents($dir.'/'.$relative, '<?php return '.var_export($contents, true).';');
    }

    app()->useLangPath($dir);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');

    return $dir;
}

function uploadsPackageLang(string $relative): array
{
    return require dirname(__DIR__, 2).'/lang/'.$relative;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/uploads-app-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) chave só no pacote: texto do pacote', function (string $locale): void {
    uploadsAppLang(["{$locale}/outro.php" => ['x' => 'y']]);

    $pacote = uploadsPackageLang("{$locale}/uploads.php");

    expect(__('uploads.stored', [], $locale))->toBe($pacote['stored'])
        ->and(__('uploads.rejected.embedded_script', [], $locale))->toBe($pacote['rejected']['embedded_script'])
        ->and(__('uploads.rejected.too_large', ['max' => 3], $locale))->toBe(str_replace(':max', '3', $pacote['rejected']['too_large']));
})->with(['pt_BR', 'en', 'es']);

it('(b) mesma chave no aplicativo: texto do aplicativo', function (string $locale): void {
    uploadsAppLang([
        "{$locale}/uploads.php" => [
            'stored' => "Recebido pelo app ({$locale})",
            'rejected' => ['embedded_script' => "Script recusado pelo app ({$locale})"],
        ],
    ]);

    expect(__('uploads.stored', [], $locale))->toBe("Recebido pelo app ({$locale})")
        ->and(__('uploads.rejected.embedded_script', [], $locale))->toBe("Script recusado pelo app ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) grupo repartido: cada chave resolve do lado certo — app e pacote no mesmo grupo', function (string $locale): void {
    uploadsAppLang(["{$locale}/uploads.php" => ['rejected' => ['empty' => "Vazio no app ({$locale})"]]]);

    $pacote = uploadsPackageLang("{$locale}/uploads.php");

    expect(__('uploads.rejected.empty', [], $locale))->toBe("Vazio no app ({$locale})")
        ->and(__('uploads.rejected.executable', [], $locale))->toBe($pacote['rejected']['executable'])
        ->and(__('uploads.avatar_updated', [], $locale))->toBe($pacote['avatar_updated']);
})->with(['pt_BR', 'en', 'es']);

it('no idioma de reserva o aplicativo também vence, e o pacote preenche o idioma pedido', function (): void {
    uploadsAppLang(['pt_BR/uploads.php' => ['stored' => 'Recebido pelo app (reserva)']]);

    app('translator')->setFallback('pt_BR');

    expect(__('uploads.stored', [], 'fr'))->toBe('Recebido pelo app (reserva)')
        ->and(__('uploads.stored', [], 'en'))->toBe(uploadsPackageLang('en/uploads.php')['stored']);
});

it('a mensagem que a API devolve é a do aplicativo quando ele a define', function (): void {
    uploadsAppLang(['en/uploads.php' => ['rejected' => ['extension_mismatch' => 'Extensão recusada pelo app']]]);

    $this->postUpload($this->owner(), fixtureArquivoEnviado(fixtureBytesPng(), 'boleto.pdf'))
        ->assertUnprocessable()
        ->assertJsonPath('error.errors.file.0', 'Extensão recusada pelo app');
});

it('põe a pasta do pacote antes da do aplicativo no carregador, junto com as do accounts, do auth e do foundation', function (): void {
    $dir = uploadsAppLang(['pt_BR/outro.php' => ['x' => 'y']]);

    app('translator');

    $paths = app('translation.loader')->paths();
    $posicao = fn (string|false $path): int|false => array_search($path, array_map(fn (string $p): string => realpath($p) ?: $p, $paths), true);

    expect($posicao($dir))->toBe(count($paths) - 1);

    foreach (['/lang', '/vendor/twstec/kit-accounts/lang', '/vendor/twstec/kit-auth/lang', '/vendor/twstec/kit-foundation/lang'] as $pasta) {
        $pacote = realpath(dirname(__DIR__, 2).$pasta);

        expect($posicao($pacote))->toBeInt()
            ->and($posicao($pacote))->toBeLessThan($posicao($dir));
    }
});
