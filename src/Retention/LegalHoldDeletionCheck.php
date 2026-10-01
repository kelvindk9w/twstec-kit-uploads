<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Retention;

use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\Contracts\DeletionCheck;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionRequest;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * A guarda legal como IMPEDIMENTO de exclusão — no mecanismo do pacote de
 * contas (Deletion\DeletionImpediments), registrado pelo provider deste
 * pacote.
 *
 * Desligado por padrão: a exclusão do dono SEGUE e o upload sob guarda é
 * desvinculado e mantido (a LGPD pede a exclusão; a lei que manda guardar
 * pede o documento — os dois se cumprem). Com
 * `uploads.legal_hold.blocks_deletion = true` (UPLOADS_LEGAL_HOLD_BLOCKS_DELETION),
 * a exclusão da pessoa ou da conta é RECUSADA inteira enquanto um upload dela
 * (das contas que sairiam, ou a foto pessoal que ela enviou) estiver sob
 * guarda — para o projeto que prefere manter a conta até o prazo.
 */
final class LegalHoldDeletionCheck implements DeletionCheck
{
    public const CODE = 'legal_hold';

    public function impediments(DeletionRequest $request): iterable
    {
        if (! filter_var(config('uploads.legal_hold.blocks_deletion', false), FILTER_VALIDATE_BOOL)) {
            return [];
        }

        $ids = $request->accountIds();
        $pessoa = $request->person?->getKey();

        $total = Accounts::asSystem('uploads:legal-hold-check', fn (): int => Upload::query()
            ->where('retain_until', '>', now())
            ->where(function (Builder $query) use ($ids, $pessoa): void {
                $query->whereIn('account_id', $ids === [] ? [0] : $ids);

                if ($pessoa !== null) {
                    $query->orWhere(fn (Builder $pessoal) => $pessoal->where('personal', true)->where('created_by', $pessoa));
                }
            })
            ->count());

        if ($total === 0) {
            return [];
        }

        return [new DeletionImpediment(self::CODE, trans_choice('uploads.legal_hold.blocks_deletion', $total, ['count' => $total]))];
    }
}
