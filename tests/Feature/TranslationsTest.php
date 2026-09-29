<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

// As traduções do pacote: mesmas chaves nos três idiomas, e resolvidas pelos
// nomes de sempre (sem namespace), somadas às do aplicativo grupo a grupo.

const UPLOADS_LOCALES = ['pt_BR', 'en', 'es'];

function uploadsLangPath(string $relative): string
{
    return dirname(__DIR__, 2).'/lang/'.$relative;
}

it('todos os idiomas têm os mesmos arquivos e chaves do pt-BR', function (): void {
    $reference = [];

    foreach (glob(uploadsLangPath('pt_BR/*.php')) ?: [] as $file) {
        $reference[basename($file)] = collect(require $file)->dot()->keys()->sort()->values();
    }

    expect(array_keys($reference))->toBe(['uploads.php']);

    foreach (['en', 'es'] as $locale) {
        foreach ($reference as $file => $keys) {
            $path = uploadsLangPath("{$locale}/{$file}");

            expect($path)->toBeFile();

            $actual = collect(require $path)->dot()->keys()->sort()->values();

            expect($actual->all())->toBe($keys->all(), "lang/{$locale}/{$file} diverge do pt-BR");
        }
    }
});

it('resolve cada chave do pacote pelo nome de sempre, nos três idiomas', function (string $locale): void {
    foreach (glob(uploadsLangPath('pt_BR/*.php')) ?: [] as $file) {
        $group = basename($file, '.php');

        foreach (collect(require $file)->dot()->keys() as $key) {
            expect(app('translator')->hasForLocale("{$group}.{$key}", $locale))
                ->toBeTrue("Falta {$group}.{$key} em {$locale}");
        }
    }
})->with(UPLOADS_LOCALES);

it('toda chave que o código do pacote pede existe no pacote — inclusive cada motivo de recusa', function (): void {
    $faltando = [];
    $pedidas = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $codigo = (string) file_get_contents((string) $file);

        preg_match_all("/__\\('(uploads\\.[a-z_.]+[a-z_])'/", $codigo, $matches);
        array_push($pedidas, ...$matches[1]);

        // Os motivos de recusa entram por concatenação ('uploads.rejected.'.$reason):
        // cada `reject('motivo')` do código é uma chave.
        preg_match_all("/reject\\('([a-z_]+)'\\)|UploadRejectedException\\('([a-z_]+)'/", $codigo, $motivos);

        foreach (array_filter([...$motivos[1], ...$motivos[2]]) as $motivo) {
            $pedidas[] = 'uploads.rejected.'.$motivo;
        }
    }

    $pedidas = array_values(array_unique($pedidas));

    foreach ($pedidas as $key) {
        [$group, $item] = explode('.', $key, 2);

        foreach (UPLOADS_LOCALES as $locale) {
            // Direto no arquivo do PACOTE: a chave não pode depender do lang/
            // do aplicativo.
            if (! Arr::has(require uploadsLangPath("{$locale}/{$group}.php"), $item)) {
                $faltando[] = "{$key} ({$locale})";
            }
        }
    }

    expect(count($pedidas))->toBeGreaterThan(12)
        ->and($faltando)->toBe([]);
});
