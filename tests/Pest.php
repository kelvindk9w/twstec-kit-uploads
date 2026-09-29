<?php

declare(strict_types=1);

use Twstec\Kit\Uploads\Tests\TestCase;

// Fixtures programáticas de arquivo (bytes reais, nada de binário no repositório).
require_once __DIR__.'/Fixtures/files.php';

// Todos os testes do pacote sobem a aplicação limpa do Testbench com os
// providers do pacote, do accounts, do auth e do foundation — nada do starter.
pest()->extend(TestCase::class)->in('Feature', 'Protections', 'Architecture');
