<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Avatar\AvatarService;
use Twstec\Kit\Uploads\Console\PruneOrphanUploads;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;
use Twstec\Kit\Uploads\UploadsServiceProvider;

// =============================================================================
// `uploads:prune-orphans` — a limpeza dos uploads sem dono (registro E
// arquivo): órfãos antigos da migração para contas, fotos pessoais que não
// são a foto de ninguém e arquivos no disco sem registro. `--dry-run` só
// conta. Agendada pelo próprio pacote.
// =============================================================================

/**
 * @return array<string, mixed>
 */
function cenarioPoda(): array
{
    $ana = User::fixture(['email_verified_at' => now()]);
    $avatars = app(AvatarService::class);

    // Foto trocada: a primeira fica sem vínculo.
    $fotoTrocada = $avatars->replace($ana, fixtureArquivoEnviado(fixtureBytesPng(), 'primeira.png'));
    $foto = $avatars->replace($ana, fixtureArquivoEnviado(fixtureBytesPngDe(2, 2), 'atual.png'));

    $daConta = Accounts::actingAs(
        app(AccountService::class)->personalAccountOf($ana),
        fn (): Upload => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'doc.pdf')),
        $ana,
    );

    // Dois órfãos da migração (só a migração os cria — direto na tabela):
    // um antigo, um recente.
    $orfao = function (string $nome, int $dias): Upload {
        $caminho = 'uploads/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($caminho, fixtureBytesPdf());

        $id = DB::table('uploads')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'codigo_publico' => 'UPL-'.strtoupper(Str::random(6)),
            'disk' => 'local',
            'path' => $caminho,
            'original_name' => $nome,
            'mime' => 'application/pdf',
            'size' => 10,
            'sha256' => str_repeat('a', 64),
            'status' => 'stored',
            'personal' => false,
            'orphaned_at' => now()->subDays($dias),
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);

        return Accounts::asSystem('teste: órfão', fn (): Upload => Upload::query()->findOrFail($id));
    };

    $orfaoAntigo = $orfao('antigo.pdf', 40);
    $orfaoRecente = $orfao('recente.pdf', 2);

    // Arquivo no disco sem registro: um velho, um recém-chegado.
    Storage::disk('local')->put('uploads/sem-registro-velho.pdf', 'x');
    touch(Storage::disk('local')->path('uploads/sem-registro-velho.pdf'), now()->subDays(3)->getTimestamp());
    Storage::disk('local')->put('avatars/sem-registro-novo.png', 'x');

    // A foto trocada precisa ter passado do prazo (24 h).
    Accounts::asSystem('teste: idade', fn () => Upload::query()->whereKey($fotoTrocada->id)->update(['created_at' => now()->subDays(2)]));

    return compact('ana', 'fotoTrocada', 'foto', 'daConta', 'orfaoAntigo', 'orfaoRecente');
}

function existeUpload(Upload $upload): bool
{
    return Accounts::asSystem('teste: existe', fn (): bool => Upload::query()->whereKey($upload->id)->exists());
}

it('--dry-run só conta: nada sai do banco nem do disco, e nada vai para a trilha', function (): void {
    $c = cenarioPoda();

    $this->artisan('uploads:prune-orphans', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] Sairiam: 1 upload(s) órfão(s), 1 foto(s) pessoal(is) sem uso, 1 arquivo(s) sem registro.')
        ->assertSuccessful();

    foreach (['fotoTrocada', 'foto', 'daConta', 'orfaoAntigo', 'orfaoRecente'] as $ficou) {
        expect(existeUpload($c[$ficou]))->toBeTrue($ficou)
            ->and(Storage::disk('local')->exists((string) $c[$ficou]->path))->toBeTrue($ficou);
    }

    expect(Storage::disk('local')->exists('uploads/sem-registro-velho.pdf'))->toBeTrue()
        ->and(AuditEvent::query()->where('action', PruneOrphanUploads::AUDIT_ACTION)->exists())->toBeFalse();
});

it('sem --dry-run: apaga o órfão antigo, a foto sem uso e o arquivo sem registro velho — e só eles', function (): void {
    $c = cenarioPoda();

    $this->artisan('uploads:prune-orphans')
        ->expectsOutputToContain('Removidos: 1 upload(s) órfão(s), 1 foto(s) pessoal(is) sem uso, 1 arquivo(s) sem registro.')
        ->assertSuccessful();

    foreach (['fotoTrocada', 'orfaoAntigo'] as $saiu) {
        expect(existeUpload($c[$saiu]))->toBeFalse($saiu)
            ->and(Storage::disk('local')->exists((string) $c[$saiu]->path))->toBeFalse($saiu);
    }

    // A foto em uso, o upload da conta e o órfão recente ficam; o arquivo sem
    // registro recém-chegado também (pode ser um envio em andamento).
    foreach (['foto', 'daConta', 'orfaoRecente'] as $ficou) {
        expect(existeUpload($c[$ficou]))->toBeTrue($ficou)
            ->and(Storage::disk('local')->exists((string) $c[$ficou]->path))->toBeTrue($ficou);
    }

    expect(Storage::disk('local')->exists('uploads/sem-registro-velho.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('avatars/sem-registro-novo.png'))->toBeTrue()
        ->and($c['ana']->fresh()->avatarUrl())->toContain('signature=');

    $linha = AuditEvent::query()->where('action', PruneOrphanUploads::AUDIT_ACTION)->sole();

    expect($linha->changes)->toBe([
        'orphans' => ['before' => 1, 'after' => 0],
        'personal' => ['before' => 1, 'after' => 0],
        'stray_files' => ['before' => 1, 'after' => 0],
    ])
        ->and($linha->context->value)->toBe('console')
        ->and(json_encode($linha->toArray()))->not->toContain('sem-registro');

    // Rodar de novo: nada mais a fazer, nenhuma linha nova na trilha.
    $this->artisan('uploads:prune-orphans')->assertSuccessful();
    expect(AuditEvent::query()->where('action', PruneOrphanUploads::AUDIT_ACTION)->count())->toBe(1);
});

it('o pacote agenda a limpeza sozinho (cron da configuração) e avisa quando ela é desligada', function (): void {
    $eventos = collect(app(Schedule::class)->events())
        ->filter(fn ($evento): bool => str_contains((string) $evento->command, 'uploads:prune-orphans'));

    expect($eventos)->toHaveCount(1)
        ->and($eventos->first()->expression)->toBe('40 3 * * *')
        ->and($eventos->first()->withoutOverlapping)->toBeTrue()
        ->and($eventos->first()->onOneServer)->toBeTrue();

    $this->bootWith(['uploads.prune.schedule' => '']);

    expect(collect(app(Schedule::class)->events())->filter(fn ($evento): bool => str_contains((string) $evento->command, 'uploads:prune-orphans')))->toHaveCount(0);

    // O aviso sai a cada boot.
    Log::spy();

    (new UploadsServiceProvider($this->app))->boot();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $mensagem): bool => str_starts_with($mensagem, 'uploads.prune.schedule vazio'));
});
