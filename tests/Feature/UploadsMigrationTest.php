<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;

// =============================================================================
// A MIGRAÇÃO dos uploads para as contas, com dados sintéticos da 1.x/F8b:
// uploads da web (`user_id`), da API (`tenant_uuid` — de conta pessoal e de
// empresa), fotos de perfil em uso (inclusive a enviada por outra pessoa e a
// que veio pela API) e órfãos (uuid que não aponta para conta nenhuma,
// registro sem dono). Ida, idempotência, volta e ida de novo.
// =============================================================================

function migracaoDosUploads(): object
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_09_28_000001_move_uploads_to_accounts.php';
}

function uploadLegado(array $dono): int
{
    return (int) DB::table('uploads')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'codigo_publico' => 'UPL-'.strtoupper(Str::random(6)),
        'disk' => 'local',
        'path' => 'uploads/'.Str::uuid().'.pdf',
        'original_name' => 'legado.pdf',
        'mime' => 'application/pdf',
        'size' => 10,
        'sha256' => str_repeat('a', 64),
        'status' => 'stored',
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
        ...$dono,
    ]);
}

/**
 * @return array<int, array{user_id: mixed, tenant_uuid: mixed}>
 */
function donosLegados(): array
{
    return DB::table('uploads')->orderBy('id')->get(['id', 'user_id', 'tenant_uuid'])
        ->mapWithKeys(fn (object $linha): array => [(int) $linha->id => [
            'user_id' => $linha->user_id === null ? null : (int) $linha->user_id,
            'tenant_uuid' => $linha->tenant_uuid,
        ]])->all();
}

/**
 * @return array<int, array{account_id: int|null, created_by: int|null, personal: bool, orphan: bool}>
 */
function donosNovos(): array
{
    return DB::table('uploads')->orderBy('id')->get(['id', 'account_id', 'created_by', 'personal', 'orphaned_at'])
        ->mapWithKeys(fn (object $linha): array => [(int) $linha->id => [
            'account_id' => $linha->account_id === null ? null : (int) $linha->account_id,
            'created_by' => $linha->created_by === null ? null : (int) $linha->created_by,
            'personal' => (bool) $linha->personal,
            'orphan' => $linha->orphaned_at !== null,
        ]])->all();
}

it('passa os uploads antigos para as contas, marca os órfãos (nunca uma conta qualquer), ida e volta', function (): void {
    $ana = User::fixture(['name' => 'Ana']);
    $bruno = User::fixture(['name' => 'Bruno']);
    $carla = User::fixture(['name' => 'Carla']);
    $dora = User::fixture(['name' => 'Dora']);
    $contas = app(AccountService::class);
    $empresa = $contas->createAccount('Empresa', $bruno);

    // Volta ao esquema anterior (dono = pessoa, dois jeitos).
    migracaoDosUploads()->down();

    expect(Schema::hasColumns('uploads', ['user_id', 'tenant_uuid']))->toBeTrue()
        ->and(Schema::hasColumn('uploads', 'account_id'))->toBeFalse();

    $ids = [
        'web' => uploadLegado(['user_id' => $ana->id]),
        'api_pessoal' => uploadLegado(['tenant_uuid' => (string) $ana->uuid]),
        'api_empresa' => uploadLegado(['tenant_uuid' => (string) $empresa->uuid]),
        'foto_da_ana' => uploadLegado(['user_id' => $ana->id]),
        'foto_da_carla_pelo_bruno' => uploadLegado(['user_id' => $bruno->id]),
        'foto_da_dora_pela_api' => uploadLegado(['tenant_uuid' => (string) $dora->uuid]),
        'orfao_uuid_de_ninguem' => uploadLegado(['tenant_uuid' => (string) Str::uuid()]),
        'orfao_sem_dono' => uploadLegado([]),
    ];

    DB::table('users')->where('id', $ana->id)->update(['avatar_upload_id' => $ids['foto_da_ana']]);
    DB::table('users')->where('id', $carla->id)->update(['avatar_upload_id' => $ids['foto_da_carla_pelo_bruno']]);
    DB::table('users')->where('id', $dora->id)->update(['avatar_upload_id' => $ids['foto_da_dora_pela_api']]);

    $antes = donosLegados();
    $fotos = DB::table('users')->whereNotNull('avatar_upload_id')->orderBy('id')->pluck('avatar_upload_id', 'id')->all();

    // IDA.
    migracaoDosUploads()->up();

    $pessoal = fn (User $pessoa): int => (int) $contas->personalAccountOf($pessoa)->id;
    $depois = donosNovos();

    expect(Schema::hasColumns('uploads', ['user_id']))->toBeFalse()
        ->and(Schema::hasColumn('uploads', 'tenant_uuid'))->toBeFalse()
        ->and($depois[$ids['web']])->toBe(['account_id' => $pessoal($ana), 'created_by' => $ana->id, 'personal' => false, 'orphan' => false])
        ->and($depois[$ids['api_pessoal']])->toBe(['account_id' => $pessoal($ana), 'created_by' => null, 'personal' => false, 'orphan' => false])
        ->and($depois[$ids['api_empresa']])->toBe(['account_id' => (int) $empresa->id, 'created_by' => null, 'personal' => false, 'orphan' => false])
        // A foto em uso enviada pela web vira PESSOAL (da pessoa, sem conta).
        ->and($depois[$ids['foto_da_ana']])->toBe(['account_id' => null, 'created_by' => $ana->id, 'personal' => true, 'orphan' => false])
        ->and($depois[$ids['foto_da_carla_pelo_bruno']])->toBe(['account_id' => null, 'created_by' => $bruno->id, 'personal' => true, 'orphan' => false])
        // A que veio pela API de uma conta que existe fica na conta (a pessoal da Dora).
        ->and($depois[$ids['foto_da_dora_pela_api']])->toBe(['account_id' => $pessoal($dora), 'created_by' => null, 'personal' => false, 'orphan' => false])
        // Órfãos: sem conta, marcados — nunca atribuídos a uma conta qualquer.
        ->and($depois[$ids['orfao_uuid_de_ninguem']])->toBe(['account_id' => null, 'created_by' => null, 'personal' => false, 'orphan' => true])
        ->and($depois[$ids['orfao_sem_dono']])->toBe(['account_id' => null, 'created_by' => null, 'personal' => false, 'orphan' => true])
        // As fotos de perfil continuam apontando para os mesmos uploads (no
        // SQLite a tabela é recriada: o vínculo é guardado e devolvido).
        ->and(DB::table('users')->whereNotNull('avatar_upload_id')->orderBy('id')->pluck('avatar_upload_id', 'id')->all())->toBe($fotos)
        ->and(User::query()->findOrFail($ana->id)->avatarUrl())->toContain('signature=')
        ->and(User::query()->findOrFail($carla->id)->avatarUrl())->toContain('signature=')
        ->and(User::query()->findOrFail($dora->id)->avatarUrl())->toContain('signature=');

    // Idempotente: rodar de novo não muda nada (nem a data de órfão).
    $orfaoEm = DB::table('uploads')->whereNotNull('orphaned_at')->orderBy('id')->pluck('orphaned_at')->all();
    migracaoDosUploads()->up();

    expect(donosNovos())->toBe($depois)
        ->and(DB::table('uploads')->whereNotNull('orphaned_at')->orderBy('id')->pluck('orphaned_at')->all())->toBe($orfaoEm);

    // VOLTA: o dono no jeito antigo. O órfão com uuid de ninguém volta sem
    // dono (o uuid que não apontava para nada não é guardado).
    migracaoDosUploads()->down();

    $esperado = $antes;
    $esperado[$ids['orfao_uuid_de_ninguem']]['tenant_uuid'] = null;

    expect(donosLegados())->toBe($esperado)
        ->and(Schema::hasColumn('uploads', 'account_id'))->toBeFalse()
        ->and(DB::table('users')->whereNotNull('avatar_upload_id')->orderBy('id')->pluck('avatar_upload_id', 'id')->all())->toBe($fotos);

    // E IDA de novo: o mesmo resultado da primeira (os dois órfãos, agora
    // os dois sem dono).
    migracaoDosUploads()->up();

    expect(donosNovos())->toBe($depois);
});
