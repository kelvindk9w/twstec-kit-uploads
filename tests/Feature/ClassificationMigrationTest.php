<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;

// =============================================================================
// A MIGRATION da classificação e da guarda legal: upload que já existe vira
// `private` (o comportamento de sempre), a chave estrangeira da conta passa a
// soltar o upload (SET NULL) em vez de levá-lo junto, a foto de perfil não se
// perde no SQLite; ida, volta e ida — e a volta RECUSA enquanto houver
// confidencial (cifrado) ou desvinculado sob guarda.
// =============================================================================

function migracaoDaClassificacao(): object
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_10_01_000001_add_classification_and_legal_hold_to_uploads.php';
}

function acaoDaChaveDaConta(): ?string
{
    foreach (Schema::getForeignKeys('uploads') as $chave) {
        if ($chave['columns'] === ['account_id']) {
            return strtolower((string) $chave['on_delete']);
        }
    }

    return null;
}

function uploadAnterior(int $contaId, ?int $criador): int
{
    return (int) DB::table('uploads')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'codigo_publico' => 'UPL-'.strtoupper(Str::random(6)),
        'account_id' => $contaId,
        'created_by' => $criador,
        'personal' => false,
        'disk' => 'local',
        'path' => 'uploads/'.Str::uuid().'.pdf',
        'original_name' => 'anterior.pdf',
        'mime' => 'application/pdf',
        'size' => 10,
        'sha256' => str_repeat('b', 64),
        'status' => 'stored',
        'created_at' => now()->subMonth(),
        'updated_at' => now()->subMonth(),
    ]);
}

it('upload que já existe vira `private`; a chave da conta passa a SET NULL; a foto não se perde; ida, volta e ida', function (): void {
    $ana = User::fixture();
    $conta = app(AccountService::class)->personalAccountOf($ana);

    migracaoDaClassificacao()->down();

    expect(Schema::hasColumn('uploads', 'classification'))->toBeFalse()
        ->and(acaoDaChaveDaConta())->toBe('cascade');

    $id = uploadAnterior((int) $conta->id, (int) $ana->id);
    $foto = uploadAnterior((int) $conta->id, (int) $ana->id);
    DB::table('users')->where('id', $ana->id)->update(['avatar_upload_id' => $foto]);

    migracaoDaClassificacao()->up();

    $linha = DB::table('uploads')->where('id', $id)->first();

    expect($linha->classification)->toBe('private')
        ->and($linha->encryption_key_id)->toBeNull()
        ->and($linha->retain_until)->toBeNull()
        ->and($linha->detached_at)->toBeNull()
        ->and(acaoDaChaveDaConta())->toBe('set null')
        ->and((int) DB::table('users')->where('id', $ana->id)->value('avatar_upload_id'))->toBe($foto);

    // Idempotente.
    migracaoDaClassificacao()->up();
    expect(acaoDaChaveDaConta())->toBe('set null');

    migracaoDaClassificacao()->down();

    expect(Schema::hasColumns('uploads', ['classification', 'encryption_key_id', 'retain_until', 'retention_reason', 'detached_at']))->toBeFalse()
        ->and(acaoDaChaveDaConta())->toBe('cascade')
        ->and(DB::table('uploads')->where('id', $id)->exists())->toBeTrue()
        ->and((int) DB::table('users')->where('id', $ana->id)->value('avatar_upload_id'))->toBe($foto);

    migracaoDaClassificacao()->up();

    expect(DB::table('uploads')->where('id', $id)->value('classification'))->toBe('private');
});

it('a volta RECUSA (nada muda) enquanto houver confidencial ou desvinculado sob guarda', function (string $caso): void {
    $ana = User::fixture();
    $conta = app(AccountService::class)->personalAccountOf($ana);
    $id = uploadAnterior((int) $conta->id, (int) $ana->id);

    DB::table('uploads')->where('id', $id)->update($caso === 'confidencial'
        ? ['classification' => 'confidential', 'encryption_key_id' => str_repeat('c', 16)]
        : ['account_id' => null, 'detached_at' => now(), 'retain_until' => now()->addYear()]);

    expect(fn () => migracaoDaClassificacao()->down())->toThrow(RuntimeException::class);

    expect(Schema::hasColumn('uploads', 'classification'))->toBeTrue()
        ->and(acaoDaChaveDaConta())->toBe('set null');
})->with(['confidencial', 'desvinculado']);
