<?php

declare(strict_types=1);

// A suíte sobe com o ambiente de uma aplicação Laravel NOVA — o fixado no
// phpunit.xml, e nada do .env do starter que o container de desenvolvimento
// injeta (ver tests/bootstrap.php). É o que faz a suíte dar o mesmo resultado
// no container e no CI.

it('não herda nenhuma variável do kit do ambiente, só as fixadas no phpunit.xml', function (): void {
    $doKit = array_values(array_filter(
        array_keys(getenv()),
        fn (string $name): bool => preg_match('/^(UPLOADS_|FILESYSTEM_|AWS_|API_KEYS_|RATE_LIMIT_|AUTH_|SECURITY_|PLATFORM_|REQUEST_LOG_|AUDIT_|BACKUP_|LOG_|REDIS_|DB_(?!CONNECTION$)|SESSION_(?!DRIVER$)|MAIL_(?!MAILER$))/', $name) === 1,
    ));

    expect($doKit)->toBe([])
        ->and(env('APP_LOCALE'))->toBe('en')
        ->and(app()->getLocale())->toBe('en')
        ->and(env('UPLOADS_DISK'))->toBeNull()
        ->and(config('uploads.disk'))->toBe('local')
        ->and(config('uploads.types.image.max_kb'))->toBe(5120);
});
