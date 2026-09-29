<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Avatar\AvatarService;
use Twstec\Kit\Uploads\Erasure\UploadEraser;
use Twstec\Kit\Uploads\Jobs\DeleteUploadFiles;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;

// =============================================================================
// LGPD — EXCLUIR A PESSOA APAGA OS ARQUIVOS DELA (banco E disco), numa
// aplicação limpa, pelo caminho que o pacote instala sozinho:
//
// - saem a foto de perfil dela, as fotos pessoais que ela enviou e os uploads
//   das contas que somem junto com ela (a pessoal e as de que era a única
//   dona);
// - FICAM os uploads que ela criou em contas de OUTRAS pessoas (são daquela
//   conta, como as chaves) e a foto que ela enviou e que é a foto de outra
//   pessoa;
// - os registros saem na transação da exclusão; os arquivos, por job na fila,
//   DEPOIS do commit (exclusão recusada ou desfeita não apaga nada);
// - excluir uma CONTA apaga os uploads dela;
// - a trilha de auditoria registra quantos e por quê — nunca o caminho.
// =============================================================================

/**
 * @return array<string, mixed>
 */
function cenarioLgpd(): array
{
    $ana = User::fixture(['name' => 'Ana', 'email_verified_at' => now()]);
    $bruno = User::fixture(['name' => 'Bruno', 'email_verified_at' => now()]);
    $service = app(AccountService::class);

    $empresaDoBruno = $service->createAccount('Empresa do Bruno', $bruno);
    $service->addMember($empresaDoBruno, $ana, AccountRole::Admin);

    $envia = fn (Account $conta, User $quem, string $nome): Upload => Accounts::actingAs(
        $conta,
        fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), $nome)),
        $quem,
    );

    $avatars = app(AvatarService::class);

    $fotoAntiga = $avatars->replace($ana, fixtureArquivoEnviado(fixtureBytesPng(), 'antiga.png'));
    $foto = $avatars->replace($ana, fixtureArquivoEnviado(fixtureBytesPngDe(2, 2), 'atual.png'));

    // A Ana (operando) enviou a foto que virou a do Bruno.
    $fotoDoBruno = $avatars->replace($bruno, fixtureArquivoEnviado(fixtureBytesPngDe(3, 3), 'bruno.png'), $ana);

    return [
        'ana' => $ana,
        'bruno' => $bruno,
        'empresaDoBruno' => $empresaDoBruno,
        'daContaDaAna' => $envia($service->personalAccountOf($ana), $ana, 'pessoal.pdf'),
        'daAnaNaEmpresa' => $envia($empresaDoBruno, $ana, 'na-empresa.pdf'),
        'doBruno' => $envia($service->personalAccountOf($bruno), $bruno, 'do-bruno.pdf'),
        'fotoAntiga' => $fotoAntiga,
        'foto' => $foto,
        'fotoDoBruno' => $fotoDoBruno,
    ];
}

function uploadExiste(Upload $upload): bool
{
    return Accounts::asSystem('teste: existe', fn (): bool => Upload::query()->whereKey($upload->id)->exists());
}

function arquivoExiste(Upload $upload): bool
{
    return Storage::disk((string) $upload->disk)->exists((string) $upload->path);
}

it('excluir a pessoa apaga do banco e do DISCO a foto dela e os uploads das contas que somem; o resto fica', function (): void {
    $c = cenarioLgpd();

    $c['ana']->delete();

    foreach (['daContaDaAna', 'fotoAntiga', 'foto'] as $saiu) {
        expect(uploadExiste($c[$saiu]))->toBeFalse($saiu)
            ->and(arquivoExiste($c[$saiu]))->toBeFalse($saiu);
    }

    // O que ela criou na conta do Bruno é da conta do Bruno — fica, sem autora.
    expect(uploadExiste($c['daAnaNaEmpresa']))->toBeTrue()
        ->and(arquivoExiste($c['daAnaNaEmpresa']))->toBeTrue()
        ->and(Accounts::asSystem('teste', fn () => Upload::query()->whereKey($c['daAnaNaEmpresa']->id)->value('created_by')))->toBeNull()
        // A foto que ela enviou e é a do Bruno fica; o resto do Bruno também.
        ->and(uploadExiste($c['fotoDoBruno']))->toBeTrue()
        ->and(arquivoExiste($c['fotoDoBruno']))->toBeTrue()
        ->and($c['bruno']->fresh()->avatarUrl())->toContain('signature=')
        ->and(uploadExiste($c['doBruno']))->toBeTrue()
        ->and(arquivoExiste($c['doBruno']))->toBeTrue();

    // Trilha: quantos e por quê — nunca o caminho.
    $apagados = AuditEvent::query()->where('action', UploadEraser::AUDIT_ACTION)->sole();
    $arquivos = AuditEvent::query()->where('action', DeleteUploadFiles::AUDIT_ACTION)->sole();

    expect($apagados->changes['uploads']['before'])->toBe(3)
        ->and($apagados->changes['reason']['after'])->toBe(UploadEraser::REASON_PERSON)
        ->and($arquivos->changes['removed']['after'])->toBe(3)
        ->and(json_encode([$apagados->toArray(), $arquivos->toArray()]))->not->toContain('uploads/')
        ->not->toContain('avatars/')
        ->not->toContain('.pdf')
        ->not->toContain('.png');
});

it('exclusão RECUSADA (dona de conta com outros membros) não apaga nada', function (): void {
    $c = cenarioLgpd();

    // Agora a Ana é dona de uma conta com o Bruno dentro.
    $daAna = app(AccountService::class)->createAccount('Empresa da Ana', $c['ana']);
    app(AccountService::class)->addMember($daAna, $c['bruno'], AccountRole::Member);

    expect(fn () => $c['ana']->delete())->toThrow(OwnerOfSharedAccountException::class);

    foreach (['daContaDaAna', 'fotoAntiga', 'foto', 'daAnaNaEmpresa'] as $ficou) {
        expect(uploadExiste($c[$ficou]))->toBeTrue($ficou)
            ->and(arquivoExiste($c[$ficou]))->toBeTrue($ficou);
    }

    expect(AuditEvent::query()->where('action', UploadEraser::AUDIT_ACTION)->exists())->toBeFalse();
});

it('exclusão recusada por OUTRA guarda depois da regra das contas também não apaga nada', function (): void {
    $c = cenarioLgpd();

    // Uma guarda do aplicativo que recusa DEPOIS de o pacote ter lido o que
    // sairia (o PersonDeleting já foi disparado).
    Event::listen('eloquent.deleting: '.User::class, function (): void {
        throw new RuntimeException('recusada por outra guarda');
    });

    expect(fn () => $c['ana']->delete())->toThrow(RuntimeException::class, 'recusada por outra guarda');

    foreach (['daContaDaAna', 'fotoAntiga', 'foto'] as $ficou) {
        expect(uploadExiste($c[$ficou]))->toBeTrue($ficou)
            ->and(arquivoExiste($c[$ficou]))->toBeTrue($ficou);
    }
});

it('os arquivos saem só DEPOIS do commit; exclusão desfeita não apaga arquivo nem registro', function (): void {
    $c = cenarioLgpd();

    // Desfeita: tudo volta, nenhum arquivo sai.
    try {
        DB::transaction(function () use ($c): void {
            $c['ana']->delete();

            throw new RuntimeException('desfaz');
        });
    } catch (RuntimeException) {
    }

    expect(User::query()->whereKey($c['ana']->id)->exists())->toBeTrue()
        ->and(uploadExiste($c['foto']))->toBeTrue()
        ->and(arquivoExiste($c['foto']))->toBeTrue()
        ->and(arquivoExiste($c['daContaDaAna']))->toBeTrue();

    // Confirmada: dentro da transação o arquivo ainda está lá; depois do
    // commit, não.
    DB::beginTransaction();
    // (a instância acima ficou marcada como apagada; a pessoa voltou no rollback)
    User::query()->findOrFail($c['ana']->id)->delete();

    expect(uploadExiste($c['foto']))->toBeFalse()
        ->and(arquivoExiste($c['foto']))->toBeTrue()
        ->and(arquivoExiste($c['daContaDaAna']))->toBeTrue();

    DB::commit();

    expect(arquivoExiste($c['foto']))->toBeFalse()
        ->and(arquivoExiste($c['daContaDaAna']))->toBeFalse();
});

it('a remoção dos arquivos vai para a FILA, marcada para depois do commit', function (): void {
    $c = cenarioLgpd();
    Queue::fake();

    $c['ana']->delete();

    Queue::assertPushed(DeleteUploadFiles::class, function (DeleteUploadFiles $job) use ($c): bool {
        $caminhos = array_column($job->files, 'path');
        sort($caminhos);
        $esperados = [$c['daContaDaAna']->path, $c['fotoAntiga']->path, $c['foto']->path];
        sort($esperados);

        return $job->afterCommit === true && $caminhos === $esperados && $job->reason === UploadEraser::REASON_PERSON;
    });

    // Com a fila parada, o arquivo continua no disco (quem apaga é o job).
    expect(arquivoExiste($c['foto']))->toBeTrue();
});

it('o job tenta de novo: arquivo que não sai do disco faz o job falhar (e voltar para a fila); depois, sai', function (): void {
    $c = cenarioLgpd();
    $pasta = dirname(Storage::disk('local')->path((string) $c['doBruno']->path));

    $job = new DeleteUploadFiles([['disk' => 'local', 'path' => (string) $c['doBruno']->path]], UploadEraser::REASON_ACCOUNT, (string) Str::uuid());

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([10, 60, 300, 900])
        ->and($job->afterCommit)->toBeTrue();

    // A pasta sem permissão de escrita: o arquivo não sai.
    chmod($pasta, 0555);

    try {
        expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    } finally {
        chmod($pasta, 0755);
    }

    expect(arquivoExiste($c['doBruno']))->toBeTrue()
        ->and(AuditEvent::query()->where('action', DeleteUploadFiles::AUDIT_ACTION)->exists())->toBeFalse();

    // A nova tentativa, com o disco de volta: sai, e a trilha registra.
    app()->call([$job, 'handle']);
    // Refazer é seguro: o que já saiu conta como ausente.
    app()->call([$job, 'handle']);

    $linhas = AuditEvent::query()->where('action', DeleteUploadFiles::AUDIT_ACTION)->orderBy('id')->get();

    expect(arquivoExiste($c['doBruno']))->toBeFalse()
        ->and($linhas)->toHaveCount(2)
        ->and($linhas[0]->changes['removed']['after'])->toBe(1)
        ->and($linhas[1]->changes['already_missing']['after'])->toBe(1);

    // Esgotadas as tentativas: o erro vai para o log, só com contagens.
    Log::spy();
    $job->failed(new RuntimeException('x'));
    Log::shouldHaveReceived('error')->withArgs(fn (string $mensagem, array $contexto): bool => $mensagem === 'upload.files_delete_failed'
        && $contexto['files'] === 1
        && ! str_contains(json_encode($contexto), (string) $c['doBruno']->path));
});

it('excluir uma CONTA apaga os uploads dela (banco e disco); os das outras contas ficam', function (): void {
    $c = cenarioLgpd();

    app(AccountService::class)->deleteAccount($c['empresaDoBruno']);

    expect(uploadExiste($c['daAnaNaEmpresa']))->toBeFalse()
        ->and(arquivoExiste($c['daAnaNaEmpresa']))->toBeFalse()
        ->and(uploadExiste($c['daContaDaAna']))->toBeTrue()
        ->and(arquivoExiste($c['daContaDaAna']))->toBeTrue()
        ->and(uploadExiste($c['doBruno']))->toBeTrue()
        ->and(uploadExiste($c['foto']))->toBeTrue();

    $linha = AuditEvent::query()->where('action', UploadEraser::AUDIT_ACTION)->sole();

    expect($linha->tenant_uuid)->toBe((string) $c['empresaDoBruno']->uuid)
        ->and($linha->changes['reason']['after'])->toBe(UploadEraser::REASON_ACCOUNT);
});

it('exclusão de conta desfeita não apaga nada', function (): void {
    $c = cenarioLgpd();

    try {
        DB::transaction(function () use ($c): void {
            app(AccountService::class)->deleteAccount($c['empresaDoBruno']);

            throw new RuntimeException('desfaz');
        });
    } catch (RuntimeException) {
    }

    expect(uploadExiste($c['daAnaNaEmpresa']))->toBeTrue()
        ->and(arquivoExiste($c['daAnaNaEmpresa']))->toBeTrue();
});
