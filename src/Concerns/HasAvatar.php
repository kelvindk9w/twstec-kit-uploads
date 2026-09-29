<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * Foto de perfil: o vínculo do model (o usuário) com um upload validado.
 *
 * Mora no módulo de Uploads, e não no model do usuário, porque é o upload
 * que sabe o que é uma foto de perfil — o módulo de autenticação não precisa
 * conhecer uploads. O model que usar esta trait precisa da coluna
 * `avatar_upload_id`.
 *
 * A FOTO É DA PESSOA, NÃO DE UMA CONTA: aparece em todas as contas dela (e
 * para quem divide uma conta com ela). Por isso ela é um upload PESSOAL (sem
 * conta — ver Models\Upload) e não é alcançada por nenhuma consulta de conta.
 * A leitura é um modo sistema RESTRITO, num ponto só (`uploads:avatar`):
 * busca exatamente o upload apontado por `avatar_upload_id` desta pessoa, e
 * só se ele for uma foto pessoal ou um upload da CONTA PESSOAL dela (o
 * caso de uma foto antiga escolhida entre os uploads da própria pessoa). A
 * foto nunca abre upload de conta de empresa nem de outra pessoa — e quem
 * aponta a foto (o perfil, o /admin) só aceita upload da própria pessoa ou
 * enviado na hora.
 *
 * @mixin Model
 */
trait HasAvatar
{
    /**
     * O vínculo cru com o upload. Numa consulta de conta ele passa pelo
     * escopo da conta atual (e a foto pessoal não aparece): para ler a foto,
     * use avatarUpload() / avatarUrl().
     *
     * @return BelongsTo<Upload, $this>
     */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'avatar_upload_id');
    }

    /**
     * O upload da foto de perfil (ou null), lido pela regra restrita.
     */
    public function avatarUpload(): ?Upload
    {
        return $this->withAvatar(fn (?Upload $upload): ?Upload => $upload);
    }

    /**
     * URL (assinada) do avatar, ou null quando não definido.
     */
    public function avatarUrl(): ?string
    {
        return $this->withAvatar(fn (?Upload $upload): ?string => $upload?->url());
    }

    /**
     * @template T
     *
     * @param  Closure(Upload|null): T  $callback
     * @return T
     */
    private function withAvatar(Closure $callback): mixed
    {
        $id = $this->getAttribute('avatar_upload_id');

        if ($id === null) {
            return $callback(null);
        }

        $dono = $this->getKey();

        return Accounts::asSystem('uploads:avatar', fn (): mixed => $callback(
            Upload::query()
                ->whereKey($id)
                ->where(fn (Builder $query) => $query
                    ->where('personal', true)
                    ->orWhereIn('account_id', Account::query()->select('id')->where('personal_user_id', $dono)))
                ->first(),
        ));
    }
}
