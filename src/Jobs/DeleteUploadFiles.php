<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;

/**
 * Remove do DISCO os arquivos de uploads que já saíram do banco (exclusão da
 * pessoa, exclusão da conta — ver Services\UploadEraser).
 *
 * - Só depois do COMMIT de quem apagou os registros (`afterCommit`): exclusão
 *   desfeita não apaga arquivo nenhum.
 * - Com NOVA TENTATIVA: arquivo que continua no disco depois do `delete`
 *   (armazenamento fora do ar, permissão) faz o job falhar e voltar para a
 *   fila, com espera crescente. Refazer é seguro: arquivo que já não existe
 *   conta como removido.
 * - Grava na trilha de auditoria (`upload.files_deleted`) QUANTOS arquivos
 *   saíram e por quê — nunca o caminho nem o conteúdo.
 * - Esgotadas as tentativas, o erro vai para o log (`upload.files_delete_failed`,
 *   só contagens); o arquivo que sobrou vira "arquivo sem registro" e sai na
 *   próxima rodada do `uploads:prune-orphans`.
 */
final class DeleteUploadFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const AUDIT_ACTION = 'upload.files_deleted';

    public int $tries = 5;

    /**
     * @param  list<array{disk: string, path: string}>  $files
     * @param  string  $reason  Motivo estável (UploadEraser::REASON_*).
     * @param  string|null  $tenantUuid  A conta a que os arquivos pertenciam (nula na foto pessoal e na poda).
     */
    public function __construct(
        public readonly array $files,
        public readonly string $reason,
        public readonly ?string $tenantUuid = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(AuditTrail $trail): void
    {
        $removidos = 0;
        $ausentes = 0;
        $restantes = 0;

        foreach ($this->files as $file) {
            $disk = Storage::disk($file['disk']);

            if (! $disk->exists($file['path'])) {
                $ausentes++;

                continue;
            }

            $disk->delete($file['path']);

            if ($disk->exists($file['path'])) {
                $restantes++;
            } else {
                $removidos++;
            }
        }

        if ($restantes > 0) {
            throw new RuntimeException("{$restantes} arquivo(s) de upload continuam no disco; nova tentativa.");
        }

        $trail->within(AuditScope::console('queue: '.self::AUDIT_ACTION), fn () => $trail->record(
            self::AUDIT_ACTION,
            null,
            [
                'files' => ['before' => count($this->files), 'after' => 0],
                'removed' => ['before' => null, 'after' => $removidos],
                'already_missing' => ['before' => null, 'after' => $ausentes],
                'reason' => ['before' => null, 'after' => $this->reason],
            ],
            'upload',
            null,
            $this->tenantUuid,
        ));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('upload.files_delete_failed', [
            'files' => count($this->files),
            'reason' => $this->reason,
            'tenant_uuid' => $this->tenantUuid,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
