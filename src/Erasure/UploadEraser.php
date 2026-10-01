<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Erasure;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Uploads\Jobs\DeleteUploadFiles;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Retention\LegalHold;

/**
 * APAGA uploads de verdade — o registro no banco e o arquivo no disco — quando
 * o dono deles deixa de existir (LGPD).
 *
 * EXCLUSÃO × APAGAMENTO: "exclusão" é o pedido sobre o TITULAR (excluir a
 * pessoa, excluir a conta — twstec/kit-accounts); "apagamento" é sumir com o
 * REGISTRO e o ARQUIVO (esta classe). A exclusão do titular pede o
 * apagamento do que é dele; o que está sob GUARDA LEGAL (Retention\LegalHold)
 * não é apagado: é DESVINCULADO — sem conta, sem autor, o nome original
 * trocado pelo código público, `detached_at` preenchido — e a recusa de
 * apagar vai para a trilha (`upload.erasure_refused`, `denied`, com o prazo e
 * o motivo da guarda). Vencido o prazo, o `uploads:erase-expired-holds`
 * (agendado pelo pacote) apaga o desvinculado.
 *
 * O que sai com a exclusão:
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

    public const REASON_HOLD_EXPIRED = 'legal_hold_expired';

    public const REFUSED_ACTION = 'upload.erasure_refused';

    public function __construct(private readonly AuditTrail $trail) {}

    /**
     * O que sai junto com a pessoa. SÓ LÊ — chamado antes de a pessoa sair do
     * banco (no PostgreSQL as contas dela somem na mesma sentença).
     *
     * @param  list<int>  $vanishingAccountIds
     * @return list<array{id: int, disk: string, path: string, tenant_uuid?: string|null}>
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
     * @param  list<array{id: int, disk: string, path: string, tenant_uuid?: string|null}>  $snapshot
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
     * apagar os arquivos depois do commit — MENOS os que estão sob guarda
     * legal neste instante: esses são desvinculados e a recusa vai para a
     * trilha (uma linha por upload, com a conta de onde saiu).
     *
     * @param  list<array{id: int, disk: string, path: string, tenant_uuid?: string|null}>  $snapshot
     */
    public function erase(array $snapshot, string $reason, ?string $tenantUuid = null): void
    {
        if ($snapshot === []) {
            return;
        }

        $ids = array_map(fn (array $item): int => $item['id'], $snapshot);

        // A guarda é conferida AGORA, no banco (não na foto tirada antes).
        $guardados = $this->system(fn () => Upload::query()->whereKey($ids)->where('retain_until', '>', now())->orderBy('id')->get());

        if ($guardados->isNotEmpty()) {
            $contas = array_column($snapshot, 'tenant_uuid', 'id');

            $this->detach($guardados->all(), fn (Upload $upload): ?string => $contas[$upload->getKey()] ?? $tenantUuid);

            $idsGuardados = array_map('intval', $guardados->modelKeys());
            $snapshot = array_values(array_filter($snapshot, fn (array $item): bool => ! in_array($item['id'], $idsGuardados, true)));
            $ids = array_map(fn (array $item): int => $item['id'], $snapshot);

            if ($snapshot === []) {
                return;
            }
        }

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
     * Os desvinculados cuja guarda venceu (ou foi tirada): apagamento,
     * registro e arquivo. Chamado pelo `uploads:erase-expired-holds`.
     *
     * @return int Quantos saíram.
     */
    public function eraseExpiredHolds(): int
    {
        $snapshot = $this->describe(fn (): Builder => Upload::query()
            ->whereNotNull('detached_at')
            ->where(fn (Builder $query) => $query->whereNull('retain_until')->orWhere('retain_until', '<=', now())));

        $this->erase($snapshot, self::REASON_HOLD_EXPIRED);

        return count($snapshot);
    }

    /**
     * Sob guarda: fica, sem dono. O nome original (dado de quem enviou) vira
     * o código público; o conteúdo, o tipo e o hash continuam — é o que a
     * guarda manda manter.
     *
     * @param  list<Upload>  $uploads
     * @param  Closure(Upload): ?string  $tenantOf  A conta de onde o upload saiu (para a trilha).
     */
    private function detach(array $uploads, Closure $tenantOf): void
    {
        $holds = app(LegalHold::class);

        foreach ($uploads as $upload) {
            $motivo = $holds->refusalMessage($upload);

            $this->system(fn () => Upload::query()->whereKey($upload->getKey())->update([
                'account_id' => null,
                'created_by' => null,
                'original_name' => $this->anonymousName($upload),
                'detached_at' => now(),
            ]));

            $this->trail->denied(self::REFUSED_ACTION, $upload, $motivo, tenantUuid: $tenantOf($upload));
        }

        Log::info('upload.erasure_refused', ['uploads' => count($uploads), 'reason' => 'legal_hold']);
    }

    private function anonymousName(Upload $upload): string
    {
        $caminho = (string) $upload->path;
        $caminho = str_ends_with($caminho, '.enc') ? substr($caminho, 0, -4) : $caminho;
        $extensao = pathinfo($caminho, PATHINFO_EXTENSION);

        return (string) $upload->codigo_publico.($extensao !== '' ? '.'.$extensao : '');
    }

    /**
     * @param  Closure(): Builder<Upload>  $query
     * @return list<array{id: int, disk: string, path: string, tenant_uuid: string|null}>
     */
    private function describe(Closure $query): array
    {
        return $this->system(function () use ($query): array {
            $uploads = $query()->orderBy('id')->get(['id', 'disk', 'path', 'account_id']);

            // A conta de cada um, lida AGORA (no PostgreSQL, a conta da pessoa
            // excluída some na mesma sentença que ela).
            $contas = Account::query()
                ->whereKey($uploads->pluck('account_id')->filter()->unique()->values()->all())
                ->pluck('uuid', 'id')
                ->all();

            return $uploads
                ->map(fn (Upload $upload): array => [
                    'id' => (int) $upload->getKey(),
                    'disk' => (string) $upload->disk,
                    'path' => (string) $upload->path,
                    'tenant_uuid' => $upload->account_id !== null ? ($contas[$upload->account_id] ?? null) : null,
                ])
                ->values()
                ->all();
        });
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
