<?php

declare(strict_types=1);

// =============================================================================
// AMBIENTE DE UMA APLICAÇÃO LARAVEL NOVA para a suíte do pacote.
//
// O container de desenvolvimento do kit injeta o .env do starter inteiro no
// ambiente do processo (APP_LOCALE, UPLOADS_DISK, UPLOADS_IMAGE_MAX_KB,
// API_KEYS_HASH_PEPPER, RATE_LIMIT_API…); o CI não injeta nada. Uma
// suíte que herdasse essas variáveis passaria no container e cairia no CI (foi
// o que aconteceu com o pacote auth). Aqui, antes de qualquer coisa, sai do
// processo toda variável que não é:
//
//   - declarada no phpunit.xml do pacote (o ambiente fixado da suíte); ou
//   - do sistema e da ferramenta (PATH, HOME, PHP_*, COMPOSER_*, CI, GITHUB_*,
//     os tokens do Pest em paralelo…), que não mudam o comportamento do kit.
//
// O PHPUnit aplica o <php> do phpunit.xml ANTES de carregar este arquivo, então
// as declaradas já estão com o valor fixado.
// =============================================================================

$declared = [];
$phpunit = dirname(__DIR__).'/phpunit.xml';

if (is_file($phpunit)) {
    $xml = simplexml_load_file($phpunit);

    foreach ($xml?->xpath('//php/env | //php/server') ?: [] as $node) {
        $declared[(string) $node['name']] = true;
    }
}

$system = '/^(PATH|HOME|HOSTNAME|USER|SHELL|PWD|TERM|LANG|LANGUAGE|LC_[A-Z_]+|TZ|TMPDIR|TMP|TEMP|PHP_[A-Z_]+|PHPIZE_DEPS|GPG_KEYS|COMPOSER(_[A-Z_]+)?|XDEBUG_[A-Z_]+|CI|GITHUB_[A-Z_]+|RUNNER_[A-Z_]+|ACTIONS_[A-Z_]+|PEST_[A-Z_]+|PARATEST|TEST_TOKEN|UNIQUE_TEST_TOKEN|COLUMNS|LINES|SHLVL|_)$/';

foreach (array_keys(getenv()) as $name) {
    if (isset($declared[$name]) || preg_match($system, $name) === 1) {
        continue;
    }

    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}

require dirname(__DIR__).'/vendor/autoload.php';
