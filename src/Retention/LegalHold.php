<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Retention;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * GUARDA LEGAL (legal hold) de um upload: "guardar até" uma data, com o
 * motivo (a lei, o contrato). Enquanto vale, o upload NÃO É APAGADO:
 *
 * - a EXCLUSÃO do dono (a pessoa, a conta — LGPD) segue: o que pode sair sai;
 *   o upload sob guarda é DESVINCULADO (sem conta, sem autor, nome original
 *   trocado pelo código público) e a recusa de apagá-lo, com o motivo e o
 *   prazo, vai para a trilha (`upload.erasure_refused`, `denied`) —
 *   Erasure\UploadEraser;
 * - a limpeza (`uploads:prune-orphans`) não o toca;
 * - `$upload->delete()` direto é recusado (UploadUnderLegalHoldException),
 *   com a recusa na trilha;
 * - quando o prazo vence, o comando agendado `uploads:erase-expired-holds`
 *   APAGA o desvinculado (registro e arquivo).
 *
 * Opcional: `uploads.legal_hold.blocks_deletion = true` faz da guarda um
 * IMPEDIMENTO da exclusão (o pacote de contas recusa a exclusão inteira
 * enquanto houver upload sob guarda — ver LegalHoldDeletionCheck).
 *
 * Pôr, mudar e tirar a guarda vão para a trilha (`upload.legal_hold_placed`,
 * `upload.legal_hold_released`) com o antes e o depois.
 */
final class LegalHold
{
    public const PLACED = 'upload.legal_hold_placed';

    public const RELEASED = 'upload.legal_hold_released';

    public const DELETE_REFUSED = 'upload.deleted';

    public function __construct(private readonly AuditTrail $trail) {}

    /**
     * Põe (ou muda) a guarda. O upload precisa estar ao alcance de quem chama
     * (a conta atual, ou o modo sistema do /admin).
     */
    public function place(Upload $upload, DateTimeInterface $until, string $reason): Upload
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 160) {
            throw new InvalidArgumentException(__('uploads.legal_hold.reason_invalid'));
        }

        $ate = Carbon::instance($until)->utc();

        if (! $ate->isFuture()) {
            throw new InvalidArgumentException(__('uploads.legal_hold.until_in_past'));
        }

        $antes = ['until' => $upload->retain_until?->toAtomString(), 'reason' => $upload->retention_reason];

        // Sem eventos de model: a trilha recebe UMA linha, esta, com o motivo
        // (no /admin a captura automática registraria outra).
        $upload->forceFill(['retain_until' => $ate, 'retention_reason' => $reason])->saveQuietly();

        $this->recordWithin(fn () => $this->trail->record(self::PLACED, $upload, [
            'retain_until' => ['before' => $antes['until'], 'after' => $ate->toAtomString()],
            'retention_reason' => ['before' => $antes['reason'], 'after' => $reason],
        ], tenantUuid: $this->tenantOf($upload)));

        return $upload;
    }

    /**
     * Tira a guarda antes do prazo (decisão registrada, com o motivo).
     */
    public function release(Upload $upload, string $reason): Upload
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 160) {
            throw new InvalidArgumentException(__('uploads.legal_hold.reason_invalid'));
        }

        $antes = ['until' => $upload->retain_until?->toAtomString(), 'reason' => $upload->retention_reason];

        $upload->forceFill(['retain_until' => null, 'retention_reason' => null])->saveQuietly();

        $this->recordWithin(fn () => $this->trail->record(self::RELEASED, $upload, [
            'retain_until' => ['before' => $antes['until'], 'after' => null],
            'retention_reason' => ['before' => $antes['reason'], 'after' => null],
            'release_reason' => ['before' => null, 'after' => $reason],
        ], tenantUuid: $this->tenantOf($upload)));

        return $upload;
    }

    /**
     * O motivo da recusa de apagar (o que o usuário e a trilha leem).
     */
    public function refusalMessage(Upload $upload): string
    {
        return __('uploads.legal_hold.erasure_refused', [
            'code' => (string) $upload->codigo_publico,
            'until' => $upload->retain_until?->format('Y-m-d') ?? '',
            'reason' => (string) $upload->retention_reason,
        ]);
    }

    /**
     * `delete()` direto num upload sob guarda: recusa, com a recusa na trilha.
     *
     * @throws UploadUnderLegalHoldException
     */
    public function refuseDirectDeletion(Upload $upload): void
    {
        if (! $upload->isUnderLegalHold()) {
            return;
        }

        $motivo = $this->refusalMessage($upload);

        $this->recordWithin(fn () => $this->trail->denied(self::DELETE_REFUSED, $upload, $motivo, tenantUuid: $this->tenantOf($upload)));

        throw new UploadUnderLegalHoldException($motivo);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function recordWithin(callable $callback): void
    {
        if ($this->trail->current() !== null) {
            $callback();

            return;
        }

        $this->trail->within(AuditScope::ambient(), $callback);
    }

    private function tenantOf(Upload $upload): ?string
    {
        $accountId = $upload->getAttribute('account_id');

        if ($accountId === null) {
            return null;
        }

        $uuid = Account::query()->whereKey($accountId)->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }
}
