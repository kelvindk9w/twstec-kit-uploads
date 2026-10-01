<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * LIMPEZA dos uploads que não têm mais dono — registro e arquivo:
 *
 * 1. ÓRFÃOS da migração para contas (`orphaned_at` preenchido: o dono foi
 *    excluído antes de os uploads serem apagados junto), depois de
 *    `uploads.prune.orphans_after_days`;
 * 2. FOTOS PESSOAIS que não são a foto de ninguém (a foto trocada, a enviada
 *    num formulário que não foi salvo), depois de
 *    `uploads.prune.personal_after_hours`;
 * 3. ARQUIVOS NO DISCO SEM REGISTRO nas pastas de upload (o arquivo que a
 *    remoção pela fila não conseguiu apagar, sobra de falha no meio de um
 *    envio), mais velhos que `uploads.prune.stray_files_after_hours` — o
 *    prazo protege o envio que está acontecendo agora (o arquivo vai para o
 *    disco um instante antes do registro).
 *
 * NUNCA toca o que está sob GUARDA LEGAL (`retain_until` no futuro) nem o
 * desvinculado pela guarda (`detached_at` — quem apaga esse é o
 * `uploads:erase-expired-holds`, quando o prazo vence). Upload que ficou sem
 * conta sem passar pelo pacote (a conta apagada direto no banco: a chave
 * estrangeira solta o upload em vez de levá-lo junto) conta como órfão.
 *
 * `--dry-run`: só conta e mostra; não apaga nada. Sem ele, apaga e registra
 * na trilha de auditoria (`upload.orphans_pruned`, só as contagens — nunca
 * caminho nem conteúdo).
 *
 * Agendado pelo próprio pacote (`uploads.prune.schedule`, cron; vazio
 * desliga, com aviso no log). Varre todas as contas: modo sistema declarado
 * (`console:uploads:prune-orphans`).
 */
final class PruneOrphanUploads extends Command
{
    public const AUDIT_ACTION = 'upload.orphans_pruned';

    protected $signature = 'uploads:prune-orphans {--dry-run : Só conta e mostra o que sairia; não apaga nada}';

    protected $description = 'Apaga uploads órfãos antigos, fotos pessoais sem uso e arquivos no disco sem registro.';

    public function handle(AuditTrail $trail): int
    {
        $simulacao = (bool) $this->option('dry-run');

        $resultado = Accounts::asSystem('console:uploads:prune-orphans', fn (): array => [
            'orphans' => $this->pruneRows($this->orphans(), $simulacao),
            'personal' => $this->pruneRows($this->unusedPersonal(), $simulacao),
            'stray_files' => $this->pruneStrayFiles($simulacao),
        ]);

        $prefixo = $simulacao ? '[dry-run] Sairiam' : 'Removidos';

        $this->info("{$prefixo}: {$resultado['orphans']} upload(s) órfão(s), {$resultado['personal']} foto(s) pessoal(is) sem uso, {$resultado['stray_files']} arquivo(s) sem registro.");

        Log::info('uploads.prune_orphans', ['dry_run' => $simulacao, ...$resultado]);

        if (! $simulacao && array_sum($resultado) > 0) {
            $trail->within(AuditScope::console($this->getName() ?? 'uploads:prune-orphans'), fn () => $trail->record(
                self::AUDIT_ACTION,
                null,
                [
                    'orphans' => ['before' => $resultado['orphans'], 'after' => 0],
                    'personal' => ['before' => $resultado['personal'], 'after' => 0],
                    'stray_files' => ['before' => $resultado['stray_files'], 'after' => 0],
                ],
                'upload',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<Upload>
     */
    private function orphans(): Builder
    {
        return $this->withoutHold(Upload::query()
            ->whereNull('detached_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $antigo) => $antigo
                    ->whereNotNull('orphaned_at')
                    ->where('orphaned_at', '<=', now()->subDays(max(0, (int) config('uploads.prune.orphans_after_days', 30)))))
                // Sem conta, sem ser foto pessoal nem órfão marcado: a conta
                // saiu por fora do pacote.
                ->orWhere(fn (Builder $solto) => $solto
                    ->whereNull('account_id')
                    ->where('personal', false)
                    ->whereNull('orphaned_at'))));
    }

    /**
     * Fora o que está sob guarda legal agora.
     *
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    private function withoutHold(Builder $query): Builder
    {
        return $query->where(fn (Builder $guarda) => $guarda->whereNull('retain_until')->orWhere('retain_until', '<=', now()));
    }

    /**
     * @return Builder<Upload>
     */
    private function unusedPersonal(): Builder
    {
        $query = $this->withoutHold(Upload::query()
            ->where('personal', true)
            ->whereNull('detached_at')
            ->where('created_at', '<=', now()->subHours(max(0, (int) config('uploads.prune.personal_after_hours', 24)))));

        $usuarios = UserModel::make();

        if (Schema::hasColumn($usuarios->getTable(), 'avatar_upload_id')) {
            $query->whereNotIn('id', UserModel::query()->select('avatar_upload_id')->whereNotNull('avatar_upload_id'));
        }

        return $query;
    }

    /**
     * Apaga (ou só conta) os registros da consulta e os arquivos deles.
     *
     * @param  Builder<Upload>  $query
     */
    private function pruneRows(Builder $query, bool $simulacao): int
    {
        if ($simulacao) {
            return $query->count();
        }

        $total = 0;

        $query->orderBy('id')->chunkById(500, function ($uploads) use (&$total): void {
            $arquivos = $uploads->map(fn (Upload $upload): array => [(string) $upload->disk, (string) $upload->path])->all();

            Upload::query()->whereKey($uploads->modelKeys())->delete();

            foreach ($arquivos as [$disco, $caminho]) {
                $this->deleteFile($disco, $caminho);
            }

            $total += $uploads->count();
        });

        return $total;
    }

    /**
     * Arquivos nas pastas de upload sem registro no banco.
     */
    private function pruneStrayFiles(bool $simulacao): int
    {
        $limite = now()->subHours(max(0, (int) config('uploads.prune.stray_files_after_hours', 24)))->getTimestamp();
        $total = 0;

        foreach ($this->disks() as $disco) {
            $storage = Storage::disk($disco);

            foreach ((array) config('uploads.prune.directories', ['uploads', 'avatars']) as $pasta) {
                $pasta = trim((string) $pasta, '/');

                if ($pasta === '' || ! $storage->directoryExists($pasta)) {
                    continue;
                }

                foreach (array_chunk($storage->allFiles($pasta), 500) as $lote) {
                    $conhecidos = Upload::query()->where('disk', $disco)->whereIn('path', $lote)->pluck('path')->all();

                    foreach (array_diff($lote, $conhecidos) as $caminho) {
                        if ($storage->lastModified($caminho) > $limite) {
                            continue;
                        }

                        $total++;

                        if (! $simulacao) {
                            $this->deleteFile($disco, $caminho);
                        }
                    }
                }
            }
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    private function disks(): array
    {
        $discos = (array) config('uploads.prune.disks', []);

        if ($discos === []) {
            $discos = [(string) config('uploads.disk', 'local')];
        }

        return array_values(array_unique(array_map('strval', $discos)));
    }

    private function deleteFile(string $disco, string $caminho): void
    {
        try {
            Storage::disk($disco)->delete($caminho);
        } catch (Throwable $exception) {
            // O arquivo que ficou vira "arquivo sem registro" e sai na próxima
            // rodada; o registro já saiu.
            Log::warning('uploads.prune_orphans.file_failed', ['disk' => $disco, 'exception' => $exception::class]);
        }
    }
}
