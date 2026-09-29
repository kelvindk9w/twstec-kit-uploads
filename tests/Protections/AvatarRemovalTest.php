<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Uploads\Avatar\AvatarService;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Tests\Fixtures\User;

// =============================================================================
// Tirar a FOTO DE PERFIL (AvatarService::remove): a pessoa volta às iniciais;
// a foto que saiu fica sem uso e é apagada pela limpeza depois do prazo —
// como a foto trocada. Nenhuma outra foto (nem a de outra pessoa) é tocada.
// =============================================================================

function fotoExiste(Upload $upload): bool
{
    return Accounts::asSystem('teste: existe', fn (): bool => Upload::query()->whereKey($upload->id)->exists());
}

it('remove a foto: sem URL, sem vínculo; o upload fica até a limpeza e sai depois do prazo', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $bia = User::fixture(['email_verified_at' => now()]);
    $avatars = app(AvatarService::class);

    $foto = $avatars->replace($ana, fixtureArquivoEnviado(fixtureBytesPng(), 'ana.png'));
    $fotoDaBia = $avatars->replace($bia, fixtureArquivoEnviado(fixtureBytesPngDe(2, 2), 'bia.png'));

    expect($ana->fresh()->avatarUrl())->toContain('signature=');

    $avatars->remove($ana);

    expect($ana->fresh()->avatar_upload_id)->toBeNull()
        ->and($ana->fresh()->avatarUrl())->toBeNull()
        ->and($ana->avatarUrl())->toBeNull()
        // Não sai na hora: a imagem ainda pode estar na tela.
        ->and(fotoExiste($foto))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $foto->path))->toBeTrue()
        // A foto de outra pessoa não muda.
        ->and($bia->fresh()->avatarUrl())->toContain('signature=');

    // Remover de novo (sem foto) não faz nada nem falha.
    $avatars->remove($ana->fresh());
    expect($ana->fresh()->avatar_upload_id)->toBeNull();

    // Depois do prazo, a limpeza leva a foto que saiu (banco e disco) — e só ela.
    Accounts::asSystem('teste: idade', fn () => Upload::query()->whereKey($foto->id)->update(['created_at' => now()->subDays(2)]));
    $this->artisan('uploads:prune-orphans')->assertSuccessful();

    expect(fotoExiste($foto))->toBeFalse()
        ->and(Storage::disk('local')->exists((string) $foto->path))->toBeFalse()
        ->and(fotoExiste($fotoDaBia))->toBeTrue();
});
