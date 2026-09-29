<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Erasure;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Uploads\Jobs\DeleteUploadFiles;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * APAGA uploads de verdade — o registro no banco e o arquivo no disco — quando
 * o dono deles deixa de existir (LGPD):
 *
 * - EXCLUSÃO DA PESSOA: a foto de perfil dela, as fotos pessoais que ela
 *   enviou e que não são a foto de mais ninguém, e os uploads das contas que
 *   somem junto com ela (a pessoal e as de que era a única dona). Os uploads
 *   que ela criou em contas de OUTRAS pessoas ficam: são daquela conta, como
 *   as chaves de API.
 * - EXCLUSÃO DA CONTA: os uploads dela.
 *
 * Em dois tempos, para que exclusão desfeita não apague nada:
 * 1. os REGISTROS saem na transação de quem exclui (se ela for desfeita, eles
 *    voltam), e a ação fica na trilha de auditoria (`upload.erased`, com a
 *    contagem e o motivo — sem caminho nem conteúdo);
 * 2. os ARQUIVOS saem por um job na fila, disparado só DEPOIS do commit, com
 *    nova tentativa (Jobs\DeleteUploadFiles).
 *
 * Quem decide QUANDO chamar é o Support\UploadLifecycle (os eventos de
 * exclusão do twstec/kit-accounts). O modo sistema aqui é um ponto só
 * (`uploads:erase`): as contas que somem não são a conta atual de ninguém.
 */
final class UploadEraser
{
    public const AUDIT_ACTION = 'upload.erased';

    public const REASON_PERSON = 'person_deleted';

    public const REASON_ACCOUNT = 'account_deleted';

    public function __construct(private readonly AuditTrail $trail) {}

    /**
     * O que sai junto com a pessoa. SÓ LÊ — chamado antes de a pessoa sair do
     * banco (no PostgreSQL as contas dela somem na mesma sentença).
     *
     * @param  list<int>  $vanishingAccountIds
     * @return list<array{id: int, disk: string, path: string}>
     */
    public function snapshotForPerson(AuthUser $user, array $vanishingAccountIds): array
    {
        $avatarId = $user->getAttribute('avatar_upload_id');

        return $this->describe(fn (): Builder => Upload::query()->where(function (Builder $query) use ($user, $vanishingAccountIds, $avatarId): void {
            $query->whereIn('account_id', $vanishingAccountIds === [] ? [0] : $vanishingAccountIds)
                ->orWhere(function (Builder $pessoal) use ($user, $avatarId): void {
                    $pessoal->where('personal', true)->where(function (Builder $dela) use ($user, $avatarId): void {
                        // A foto de perfil dela.
                        $dela->whereKey($avatarId ?? 0)
                            // As fotos pessoais que ela mesma enviou — menos a
                            // que é a foto de OUTRA pessoa (um admin que enviou
                            // a foto de alguém pelo /admin não leva a foto dele).
                            ->orWhere(fn (Builder $enviadas) => $enviadas
                                ->where('created_by', $user->getKey())
                                ->whereNotIn('id', $this->avatarsOfOthers($user)));
                    });
                });
        }));
    }

    /**
     * Depois que a pessoa saiu: apaga o que saiu com ela e tira o nome dela
     * (`created_by`) dos uploads que ficam — os das contas de outras pessoas
     * e a foto que ela enviou e é a de outra pessoa. Sem depender de a chave
     * estrangeira estar ligada no banco (como o pacote de contas faz com
     * projetos e chaves).
     *
     * @param  list<array{id: int, disk: string, path: string}>  $snapshot
     */
    public function eraseForPerson(mixed $userId, array $snapshot): void
    {
        $this->erase($snapshot, self::REASON_PERSON);

        $this->system(fn () => Upload::query()->where('created_by', $userId)->update(['created_by' => null]));
    }

    /**
     * Os uploads da conta. Lê e apaga na hora: chamado DENTRO da transação da
     * exclusão da conta (AccountDeleting).
     */
    public function eraseAccount(Account $account): void
    {
        $snapshot = $this->describe(fn (): Builder => Upload::query()->where('account_id', $account->getKey()));

        $this->erase($snapshot, self::REASON_ACCOUNT, (string) $account->uuid);
    }

    /**
     * Apaga os registros agora (na transação corrente, se houver) e manda
     * apagar os arquivos depois do commit.
     *
     * @param  list<array{id: int, disk: string, path: string}>  $snapshot
     */
    public function erase(array $snapshot, string $reason, ?string $tenantUuid = null): void
    {
        if ($snapshot === []) {
            return;
        }

        $ids = array_map(fn (array $item): int => $item['id'], $snapshot);

        // Em massa (sem eventos de model): a trilha recebe UMA linha com a
        // contagem, não uma por arquivo.
        $this->system(fn () => Upload::query()->whereKey($ids)->delete());

        $this->trail->record(
            self::AUDIT_ACTION,
            null,
            [
                'uploads' => ['before' => count($snapshot), 'after' => 0],
                'reason' => ['before' => null, 'after' => $reason],
            ],
            'upload',
            null,
            $tenantUuid,
        );

        DeleteUploadFiles::dispatch(
            array_map(fn (array $item): array => ['disk' => $item['disk'], 'path' => $item['path']], $snapshot),
            $reason,
            $tenantUuid,
        );
    }

    /**
     * @param  Closure(): Builder<Upload>  $query
     * @return list<array{id: int, disk: string, path: string}>
     */
    private function describe(Closure $query): array
    {
        return $this->system(fn (): array => $query()
            ->orderBy('id')
            ->get(['id', 'disk', 'path'])
            ->map(fn (Upload $upload): array => [
                'id' => (int) $upload->getKey(),
                'disk' => (string) $upload->disk,
                'path' => (string) $upload->path,
            ])
            ->values()
            ->all());
    }

    /**
     * Subconsulta com os ids das fotos de perfil das OUTRAS pessoas (vazia
     * quando o model de usuário do aplicativo não tem a coluna da foto).
     *
     * @return Builder<Model>|list<int>
     */
    private function avatarsOfOthers(AuthUser $user): Builder|array
    {
        $model = UserModel::make();

        if (! Schema::hasColumn($model->getTable(), 'avatar_upload_id')) {
            return [];
        }

        return UserModel::query()
            ->select('avatar_upload_id')
            ->whereKeyNot($user->getKey())
            ->whereNotNull('avatar_upload_id');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function system(Closure $callback): mixed
    {
        return Accounts::asSystem('uploads:erase', $callback);
    }
}
