<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Twstec\Kit\Accounts\Account\Concerns\BelongsToAccount;
use Twstec\Kit\Accounts\Account\Scopes\AccountScope;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// TRAVA DO "ESQUECIMENTO" no pacote de uploads (a mesma do pacote de contas e
// do starter): o upload é dado de CONTA e nada o lê por fora do escopo.
//
// Reprova:
// 1. `withoutGlobalScope(s)`, `newQueryWithoutScopes()`, `newModelQuery()`,
//    `->getQuery()` e query builder cru/SQL na tabela `uploads`;
// 2. modo sistema (`Accounts::asSystem`) fora da lista revisada abaixo, ou
//    sem motivo literal; conta explícita (`Accounts::actingAs`) no código;
// 3. o model sem o escopo da conta.
// =============================================================================

/**
 * Onde o pacote entra em modo sistema, e por quê.
 */
const UPLOADS_SYSTEM_MODE_ALLOWED = [
    // A foto de perfil é da PESSOA: gravar a foto pessoal (sem conta).
    'src/Services/SecureUploadService.php' => 1,
    // Ler a foto de perfil — restrita ao upload que a própria pessoa aponta
    // (foto pessoal ou da conta pessoal dela).
    'src/Concerns/HasAvatar.php' => 1,
    // Exclusão da pessoa ou da conta (LGPD): as contas que somem não são a
    // conta atual de ninguém.
    'src/Erasure/UploadEraser.php' => 1,
    // Limpeza agendada: varre os uploads sem dono de todas as contas.
    'src/Console/PruneOrphanUploads.php' => 1,
];

/**
 * @return array<string, string>
 */
function uploadsPackageSources(): array
{
    $root = dirname(__DIR__, 2);
    $arquivos = [];

    foreach ((new Finder)->files()->in($root.'/src')->name('*.php') as $file) {
        $arquivos[str_replace($root.'/', '', $file->getRealPath())] = $file->getContents();
    }

    ksort($arquivos);

    return $arquivos;
}

/**
 * @return array{total: int, sem_motivo: int}
 */
function uploadsStaticCalls(string $codigo, string $metodo): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($codigo),
        fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));

    $total = 0;
    $semMotivo = 0;

    foreach ($tokens as $i => $token) {
        $nome = ltrim($token->text, '\\');

        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || ! ($nome === 'Accounts' || str_ends_with($nome, '\\Accounts'))) {
            continue;
        }

        if (($tokens[$i + 1]->text ?? '') !== '::' || ($tokens[$i + 2]->text ?? '') !== $metodo || ($tokens[$i + 3]->text ?? '') !== '(') {
            continue;
        }

        $total++;
        $motivo = $tokens[$i + 4] ?? null;

        if ($motivo === null || ! $motivo->is(T_CONSTANT_ENCAPSED_STRING) || trim($motivo->text, '\'" ') === '') {
            $semMotivo++;
        }
    }

    return ['total' => $total, 'sem_motivo' => $semMotivo];
}

/**
 * @return list<string>
 */
function uploadsIsolationViolations(string $codigo): array
{
    $texto = '';

    foreach (PhpToken::tokenize($codigo) as $token) {
        if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
            $texto .= $token->text;
        }
    }

    $violacoes = [];

    foreach (['withoutGlobalScope', 'newQueryWithoutScopes', 'newModelQuery', '->getQuery()'] as $atalho) {
        if (str_contains($texto, $atalho)) {
            $violacoes[] = "usa {$atalho}";
        }
    }

    if (preg_match("/(?:table|from|join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]uploads['\"]/", $texto) === 1) {
        $violacoes[] = 'consulta crua em uploads';
    }

    if (preg_match("/(?:select|statement|update|delete|insert|unprepared)\\(\\s*['\"][^'\"]*\\b(?:from|into|update|join)\\s+uploads\\b/i", $texto) === 1) {
        $violacoes[] = 'SQL literal em uploads';
    }

    return $violacoes;
}

it('nada no pacote pula o escopo da conta nem consulta a tabela de uploads por fora', function (): void {
    $violacoes = [];

    foreach (uploadsPackageSources() as $caminho => $codigo) {
        foreach (uploadsIsolationViolations($codigo) as $problema) {
            $violacoes[] = "{$caminho}: {$problema}";
        }
    }

    expect($violacoes)->toBe([]);
});

it('modo sistema só onde foi revisado, sempre com motivo; nenhuma conta explícita no código', function (): void {
    $encontrado = [];
    $comConta = [];

    foreach (uploadsPackageSources() as $caminho => $codigo) {
        ['total' => $total, 'sem_motivo' => $semMotivo] = uploadsStaticCalls($codigo, 'asSystem');

        expect($semMotivo)->toBe(0, "{$caminho}: modo sistema sem motivo literal")
            ->and(uploadsStaticCalls($codigo, 'systemModeForRequest')['total'])->toBe(0, $caminho);

        if ($total > 0) {
            $encontrado[$caminho] = $total;
        }

        if (uploadsStaticCalls($codigo, 'actingAs')['total'] > 0) {
            $comConta[] = $caminho;
        }
    }

    $permitido = UPLOADS_SYSTEM_MODE_ALLOWED;
    ksort($encontrado);
    ksort($permitido);

    expect($encontrado)->toBe($permitido)
        ->and($comConta)->toBe([]);
});

it('o upload carrega o escopo da conta', function (): void {
    expect(in_array(BelongsToAccount::class, class_uses_recursive(Upload::class), true))->toBeTrue()
        ->and((new Upload)->hasGlobalScope(AccountScope::class))->toBeTrue();
});

it('a trava não é cega', function (): void {
    expect(uploadsIsolationViolations('<?php Upload::query()->withoutGlobalScopes()->get();'))->toBe(['usa withoutGlobalScope'])
        ->and(uploadsIsolationViolations("<?php DB::table('uploads')->delete();"))->toBe(['consulta crua em uploads'])
        ->and(uploadsIsolationViolations("<?php DB::select('select * from uploads');"))->toBe(['SQL literal em uploads'])
        ->and(uploadsIsolationViolations("<?php // DB::table('uploads')\n\$x = 1;"))->toBe([])
        ->and(uploadsStaticCalls("<?php Accounts::asSystem('m', fn () => 1); Accounts::asSystem(\$m, fn () => 1);", 'asSystem'))
        ->toBe(['total' => 2, 'sem_motivo' => 1]);
});
