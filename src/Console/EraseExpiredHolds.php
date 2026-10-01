<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Uploads\Erasure\UploadEraser;

/**
 * APAGAMENTO dos uploads que ficaram só pela GUARDA LEGAL depois da exclusão
 * do dono (`detached_at` preenchido) e cuja guarda venceu (ou foi tirada):
 * registro e arquivo, pelo mesmo caminho da exclusão (Erasure\UploadEraser —
 * registro agora, arquivo pela fila depois do commit, `upload.erased` com o
 * motivo `legal_hold_expired` na trilha).
 *
 * Agendado pelo próprio pacote (`uploads.legal_hold.schedule`, cron; vazio
 * desliga, com aviso no log a cada boot).
 */
final class EraseExpiredHolds extends Command
{
    protected $signature = 'uploads:erase-expired-holds';

    protected $description = 'Apaga os uploads de donos já excluídos cuja guarda legal venceu.';

    public function handle(UploadEraser $eraser, AuditTrail $trail): int
    {
        $total = $trail->within(AuditScope::console($this->getName() ?? 'uploads:erase-expired-holds'), fn (): int => $eraser->eraseExpiredHolds());

        $this->info("Apagados: {$total} upload(s) com a guarda legal vencida.");

        Log::info('uploads.erase_expired_holds', ['erased' => $total]);

        return self::SUCCESS;
    }
}
