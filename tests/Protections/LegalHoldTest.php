<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\Exceptions\DeletionImpededException;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Avatar\AvatarService;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Erasure\UploadEraser;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Retention\LegalHold;
use Twstec\Kit\Uploads\Retention\LegalHoldDeletionCheck;
use Twstec\Kit\Uploads\Retention\UploadUnderLegalHoldException;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;
use Twstec\Kit\Uploads\UploadsServiceProvider;

// =============================================================================
// RETENÇÃO LEGAL ("guardar até") — EXCLUSÃO × APAGAMENTO (aplicação limpa):
//
// - excluir a conta (ou a pessoa) com upload sob guarda: a exclusão segue, o
//   arquivo CONTINUA (registro desvinculado, sem conta e sem autor, nome
//   trocado pelo código) e a recusa de apagar está na trilha com o motivo;
// - o que não está sob guarda sai como sempre;
// - depois do prazo, o comando agendado apaga registro e arquivo;
// - `delete()` direto e a limpeza não tocam o que está sob guarda;
// - com `blocks_deletion`, a guarda vira IMPEDIMENTO: a exclusão é recusada
//   inteira (mecanismo de impedimentos do pacote de contas).
// =============================================================================

/**
 * @return array{ana: User, empresa: Account, guardado: Upload, comum: Upload}
 */
function cenarioGuarda(bool $confidencial = true): array
{
    $ana = User::fixture(['name' => 'Ana', 'email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa da Ana', $ana);

    $envia = fn (string $nome, ?UploadClassification $classificacao = null): Upload => Accounts::actingAs(
        $empresa,
        fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(pdfConfidencial($nome), $nome.'.pdf'), classification: $classificacao),
        $ana,
    );

    $guardado = $envia('CONTRATO-DA-ANA', $confidencial ? UploadClassification::Confidential : null);
    $comum = $envia('RASCUNHO');

    Accounts::actingAs($empresa, fn () => app(LegalHold::class)->place($guardado, now()->addYears(5), 'Contrato: guarda de 5 anos'), $ana);

    return ['ana' => $ana, 'empresa' => $empresa, 'guardado' => $guardado->refresh(), 'comum' => $comum];
}

function registroDoUpload(Upload $upload): ?Upload
{
    return Accounts::asSystem('teste: registro', fn (): ?Upload => Upload::query()->whereKey($upload->getKey())->first());
}

beforeEach(function (): void {
    ligarConfidenciais();
});

it('excluir a CONTA com upload sob guarda: o arquivo continua (desvinculado), a recusa está na trilha; o resto sai', function (): void {
    $c = cenarioGuarda();
    $contaUuid = (string) $c['empresa']->uuid;

    app(AccountService::class)->deleteAccount($c['empresa']);

    $guardado = registroDoUpload($c['guardado']);

    expect(Account::query()->whereKey($c['empresa']->id)->exists())->toBeFalse()
        // Fica: registro e arquivo — sem conta, sem autor, sem o nome original.
        ->and($guardado)->not->toBeNull()
        ->and($guardado->account_id)->toBeNull()
        ->and($guardado->created_by)->toBeNull()
        ->and($guardado->isDetached())->toBeTrue()
        ->and($guardado->original_name)->toBe($guardado->codigo_publico.'.pdf')
        ->and(Storage::disk('local')->exists((string) $guardado->path))->toBeTrue()
        ->and(conteudoDecifrado($guardado))->toContain('CONTRATO-DA-ANA')
        // O que não estava sob guarda saiu (banco e disco).
        ->and(registroDoUpload($c['comum']))->toBeNull()
        ->and(Storage::disk('local')->exists((string) $c['comum']->path))->toBeFalse();

    $recusa = AuditEvent::query()->where('action', UploadEraser::REFUSED_ACTION)->sole();

    expect($recusa->outcome)->toBe(AuditOutcome::Denied)
        ->and($recusa->subject_uuid)->toBe((string) $c['guardado']->uuid)
        ->and($recusa->tenant_uuid)->toBe($contaUuid)
        ->and($recusa->reason)->toContain((string) $c['guardado']->codigo_publico)
        ->and($recusa->reason)->toContain($c['guardado']->retain_until->format('Y-m-d'))
        ->and($recusa->reason)->toContain('Contrato: guarda de 5 anos')
        ->and(AuditEvent::query()->where('action', UploadEraser::AUDIT_ACTION)->sole()->changes['uploads']['before'])->toBe(1);
});

it('excluir a PESSOA (com a conta pessoal) respeita a guarda do mesmo jeito', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $pessoal = app(AccountService::class)->personalAccountOf($ana);

    $upload = Accounts::actingAs($pessoal, fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(pdfConfidencial('IDENTIDADE'), 'rg.pdf'), classification: UploadClassification::Confidential), $ana);
    $foto = app(AvatarService::class)->replace($ana, fixtureArquivoEnviado(fixtureBytesPng(), 'foto.png'));

    Accounts::actingAs($pessoal, fn () => app(LegalHold::class)->place($upload, now()->addYear(), 'Norma de identificação do cliente'), $ana);

    $ana->delete();

    $ficou = registroDoUpload($upload);

    expect($ficou)->not->toBeNull()
        ->and($ficou->isDetached())->toBeTrue()
        ->and($ficou->account_id)->toBeNull()
        ->and(Storage::disk('local')->exists((string) $ficou->path))->toBeTrue()
        // A foto (sem guarda) saiu como sempre.
        ->and(registroDoUpload($foto))->toBeNull()
        ->and(AuditEvent::query()->where('action', UploadEraser::REFUSED_ACTION)->where('subject_uuid', $upload->uuid)->sole()->tenant_uuid)->toBe((string) $pessoal->uuid);
});

it('DEPOIS do prazo, o comando agendado APAGA o desvinculado (registro e arquivo); antes, não', function (): void {
    $c = cenarioGuarda();
    app(AccountService::class)->deleteAccount($c['empresa']);
    $caminho = (string) registroDoUpload($c['guardado'])->path;

    $this->artisan('uploads:erase-expired-holds')->expectsOutputToContain('Apagados: 0')->assertSuccessful();

    expect(registroDoUpload($c['guardado']))->not->toBeNull();

    $this->travelTo(now()->addYears(5)->addDay());

    $this->artisan('uploads:erase-expired-holds')->expectsOutputToContain('Apagados: 1')->assertSuccessful();

    expect(registroDoUpload($c['guardado']))->toBeNull()
        ->and(Storage::disk('local')->exists($caminho))->toBeFalse()
        ->and(AuditEvent::query()->where('action', UploadEraser::AUDIT_ACTION)->get()->pluck('changes.reason.after')->all())
        ->toContain(UploadEraser::REASON_HOLD_EXPIRED);
});

it('o pacote AGENDA o apagamento das guardas vencidas; cron vazio desliga com aviso', function (): void {
    $eventos = collect(app(Schedule::class)->events())->map(fn ($evento): string => (string) $evento->command);

    expect($eventos->filter(fn (string $comando): bool => str_contains($comando, 'uploads:erase-expired-holds')))->toHaveCount(1);

    config(['uploads.legal_hold.schedule' => '']);
    Log::spy();

    (new UploadsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'uploads:erase-expired-holds'))->once();
});

it('delete() direto num upload sob guarda é RECUSADO, com a recusa na trilha; sem guarda, sai', function (): void {
    $c = cenarioGuarda(confidencial: false);

    expect(fn () => Accounts::actingAs($c['empresa'], fn () => $c['guardado']->delete(), $c['ana']))
        ->toThrow(UploadUnderLegalHoldException::class);

    expect(registroDoUpload($c['guardado']))->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'upload.deleted')->where('outcome', AuditOutcome::Denied)->sole()->subject_uuid)->toBe((string) $c['guardado']->uuid);

    Accounts::actingAs($c['empresa'], fn () => $c['comum']->delete(), $c['ana']);

    expect(registroDoUpload($c['comum']))->toBeNull();
});

it('a LIMPEZA não toca o que está sob guarda nem o desvinculado', function (): void {
    $c = cenarioGuarda();
    app(AccountService::class)->deleteAccount($c['empresa']);

    // Um órfão antigo sob guarda e uma foto pessoal sem uso sob guarda.
    $orfao = enviaParaGuardaOrfao();

    $this->travel(60)->days();
    $this->artisan('uploads:prune-orphans')->assertSuccessful();

    expect(registroDoUpload($c['guardado']))->not->toBeNull()
        ->and(registroDoUpload($orfao))->not->toBeNull();
});

it('pôr e tirar a guarda vão para a trilha com o antes e o depois; prazo no passado e motivo vazio são recusados', function (): void {
    $c = cenarioGuarda(confidencial: false);
    $guarda = app(LegalHold::class);

    $posta = AuditEvent::query()->where('action', LegalHold::PLACED)->sole();

    expect($posta->changes['retention_reason']['after'])->toBe('Contrato: guarda de 5 anos')
        ->and($posta->subject_uuid)->toBe((string) $c['guardado']->uuid)
        ->and($posta->tenant_uuid)->toBe((string) $c['empresa']->uuid);

    expect(fn () => $guarda->place($c['guardado'], now()->subDay(), 'motivo'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $guarda->place($c['guardado'], now()->addDay(), '   '))->toThrow(InvalidArgumentException::class);

    Accounts::actingAs($c['empresa'], fn () => $guarda->release($c['guardado'], 'Processo encerrado'), $c['ana']);

    $tirada = AuditEvent::query()->where('action', LegalHold::RELEASED)->sole();

    expect($tirada->changes['release_reason']['after'])->toBe('Processo encerrado')
        ->and($tirada->changes['retain_until']['after'])->toBeNull()
        ->and(registroDoUpload($c['guardado'])->isUnderLegalHold())->toBeFalse();
});

it('guarda VENCIDA não segura nada: a exclusão apaga como sempre', function (): void {
    $c = cenarioGuarda();

    $this->travelTo(now()->addYears(5)->addDay());

    app(AccountService::class)->deleteAccount($c['empresa']);

    expect(registroDoUpload($c['guardado']))->toBeNull()
        ->and(AuditEvent::query()->where('action', UploadEraser::REFUSED_ACTION)->exists())->toBeFalse();
});

it('com `blocks_deletion`, a guarda é IMPEDIMENTO: a exclusão da conta e da pessoa é recusada inteira, nada sai', function (): void {
    config(['uploads.legal_hold.blocks_deletion' => true]);
    $c = cenarioGuarda();

    expect(fn () => app(AccountService::class)->deleteAccount($c['empresa']))
        ->toThrow(DeletionImpededException::class);

    expect(Account::query()->whereKey($c['empresa']->id)->exists())->toBeTrue()
        ->and(registroDoUpload($c['comum']))->not->toBeNull()
        ->and(registroDoUpload($c['guardado'])->account_id)->toBe($c['empresa']->id);

    // A pessoa: a empresa sairia junto (ela é a única dona).
    try {
        DB::transaction(fn () => $c['ana']->delete());
        $this->fail('A exclusão deveria ter sido recusada.');
    } catch (DeletionImpededException $exception) {
        expect($exception->codes())->toBe([LegalHoldDeletionCheck::CODE])
            ->and($exception->getMessage())->toBe(trans_choice('uploads.legal_hold.blocks_deletion', 1, ['count' => 1]));
    }

    expect(User::query()->whereKey($c['ana']->id)->exists())->toBeTrue()
        ->and(registroDoUpload($c['comum']))->not->toBeNull()
        // A recusa da exclusão da pessoa ficou na trilha, mesmo com a
        // transação de quem chamou desfeita.
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->where('subject_uuid', $c['ana']->uuid)->count())->toBe(1);
});

function enviaParaGuardaOrfao(): Upload
{
    // Órfão da migração (sem conta): só existe por escrita direta no banco.
    $id = DB::table('uploads')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'codigo_publico' => 'UPL-'.strtoupper(Str::random(6)),
        'disk' => 'local',
        'path' => 'uploads/orfao-guardado.pdf',
        'original_name' => 'orfao.pdf',
        'mime' => 'application/pdf',
        'size' => 1,
        'sha256' => str_repeat('a', 64),
        'status' => 'stored',
        'classification' => 'private',
        'personal' => false,
        'orphaned_at' => now()->subYear(),
        'retain_until' => now()->addYears(10),
        'retention_reason' => 'Guarda fiscal',
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ]);

    Storage::disk('local')->put('uploads/orfao-guardado.pdf', 'x');

    return Accounts::asSystem('teste: arranjo', fn (): Upload => Upload::query()->whereKey($id)->firstOrFail());
}
