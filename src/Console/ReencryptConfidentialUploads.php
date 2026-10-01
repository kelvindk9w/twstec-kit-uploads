<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Uploads\Confidential\ConfidentialStorage;
use Twstec\Kit\Uploads\Confidential\Exceptions\ConfidentialStorageUnavailableException;
use Twstec\Kit\Uploads\Confidential\Exceptions\UndecryptableUploadException;
use Twstec\Kit\Uploads\Confidential\Keyring;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * ROTAÇÃO da chave dos uploads confidenciais, sem tirar nada do ar: recifra
 * com a chave ATUAL todo arquivo confidencial que ainda está numa chave
 * anterior.
 *
 * Para cada arquivo:
 * 1. lê o objeto antigo e decifra (com a chave anterior, que continua
 *    configurada), cifrando de novo bloco a bloco com a atual num objeto
 *    NOVO — o antigo continua lá e continua sendo entregue;
 * 2. confere o sha256 do texto claro com o do registro;
 * 3. troca o registro (caminho + versão da chave) numa atualização
 *    condicional — só se ninguém mexeu nele no meio;
 * 4. apaga o objeto antigo.
 *
 * IDEMPOTENTE e RETOMÁVEL: só pega o que ainda não está na chave atual; parar
 * no meio (queda, Ctrl+C) deixa cada arquivo ou no estado antigo ou no novo,
 * nunca sem leitura. Um objeto novo que ficou sem registro (queda entre 1 e 3)
 * ou um antigo que não saiu (queda entre 3 e 4) é "arquivo sem registro" e
 * sai na limpeza (`uploads:prune-orphans`). Uma rodada por vez (trava no
 * cache).
 *
 * No fim: quantos foram, quantos falharam (com o motivo no log, sem conteúdo)
 * e, quando nenhum arquivo usa mais as chaves anteriores, o aviso de que elas
 * podem sair de UPLOADS_ENCRYPTION_PREVIOUS_KEYS. A rodada vai para a trilha
 * (`upload.reencrypted`, só contagens e ids de chave).
 */
final class ReencryptConfidentialUploads extends Command
{
    public const AUDIT_ACTION = 'upload.reencrypted';

    protected $signature = 'uploads:reencrypt
        {--chunk=100 : Quantos registros ler por vez}
        {--dry-run : Só conta o que falta recifrar; não muda nada}';

    protected $description = 'Recifra com a chave atual os uploads confidenciais que ainda estão numa chave anterior (rotação sem indisponibilidade).';

    public function handle(ConfidentialStorage $storage, AuditTrail $trail): int
    {
        $keyring = Keyring::fromConfig();

        try {
            $atual = $keyring->current();
        } catch (ConfidentialStorageUnavailableException $exception) {
            $this->components->error('Sem chave atual utilizável ('.$exception->reason.'): nada a recifrar. Configure UPLOADS_ENCRYPTION_KEY.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $pendentes = $this->system(fn (): int => $this->pending($atual->id)->count());

            $this->info("[dry-run] {$pendentes} upload(s) confidencial(is) fora da chave atual ({$atual->id}).");

            return self::SUCCESS;
        }

        $trava = Cache::lock('uploads:reencrypt', 3600);

        if (! $trava->get()) {
            $this->components->error('Já há uma rotação em andamento (uploads:reencrypt).');

            return self::FAILURE;
        }

        try {
            $resultado = $this->system(fn (): array => $this->reencryptAll($storage, $atual->id, max(1, (int) $this->option('chunk'))));
            $restantes = $this->system(fn (): int => $this->pending($atual->id)->count());
            $emUso = $this->system(fn (): array => Upload::query()
                ->where('classification', 'confidential')
                ->whereIn('encryption_key_id', $keyring->previousIds() === [] ? [''] : $keyring->previousIds())
                ->distinct()
                ->pluck('encryption_key_id')
                ->all());
        } finally {
            $trava->release();
        }

        $this->info("Recifrados: {$resultado['done']}. Falharam: {$resultado['failed']}. Mudaram no meio (ficam para a próxima rodada): {$resultado['skipped']}. Ainda fora da chave atual: {$restantes}.");

        $livres = array_values(array_diff($keyring->previousIds(), $emUso));

        if ($livres !== [] && $restantes === 0) {
            $this->components->info('Nenhum arquivo usa mais as chaves anteriores ('.implode(', ', $livres).'): elas podem sair de UPLOADS_ENCRYPTION_PREVIOUS_KEYS.');
        }

        if ($resultado['done'] + $resultado['failed'] > 0) {
            $trail->within(AuditScope::console($this->getName() ?? 'uploads:reencrypt'), fn () => $trail->record(
                self::AUDIT_ACTION,
                null,
                [
                    'reencrypted' => ['before' => null, 'after' => $resultado['done']],
                    'failed' => ['before' => null, 'after' => $resultado['failed']],
                    'remaining' => ['before' => null, 'after' => $restantes],
                    'key_id' => ['before' => null, 'after' => $atual->id],
                ],
                'upload',
            ));
        }

        Log::info('uploads.reencrypt', [...$resultado, 'remaining' => $restantes, 'key_id' => $atual->id]);

        return $resultado['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{done: int, failed: int, skipped: int}
     */
    private function reencryptAll(ConfidentialStorage $storage, string $keyId, int $chunk): array
    {
        $resultado = ['done' => 0, 'failed' => 0, 'skipped' => 0];

        $this->pending($keyId)->chunkById($chunk, function (Collection $uploads) use ($storage, &$resultado): void {
            foreach ($uploads as $upload) {
                /** @var Upload $upload */
                $resultado[$this->reencrypt($storage, $upload)]++;
            }
        });

        return $resultado;
    }

    /**
     * @return 'done'|'failed'|'skipped'
     */
    private function reencrypt(ConfidentialStorage $storage, Upload $upload): string
    {
        $antigo = (string) $upload->path;
        $diretorio = trim(str_replace('\\', '/', dirname($antigo)), './');
        $semEnc = str_ends_with($antigo, '.enc') ? substr($antigo, 0, -4) : $antigo;
        $extensao = pathinfo($semEnc, PATHINFO_EXTENSION);
        $novo = ($diretorio !== '' ? $diretorio.'/' : '').Str::uuid()->toString().($extensao !== '' ? '.'.$extensao : '').'.enc';

        try {
            ['path' => $caminho, 'key_id' => $chave] = $storage->reencrypt($upload, $novo);
        } catch (UndecryptableUploadException $exception) {
            Log::warning('upload.reencrypt_failed', ['upload_uuid' => $upload->uuid, 'reason' => $exception->reason]);

            return 'failed';
        } catch (RuntimeException $exception) {
            Log::warning('upload.reencrypt_failed', ['upload_uuid' => $upload->uuid, 'reason' => 'storage', 'exception' => $exception::class]);

            return 'failed';
        }

        $trocou = Upload::query()
            ->whereKey($upload->getKey())
            ->where('path', $antigo)
            ->where(fn (Builder $query) => $upload->encryption_key_id === null
                ? $query->whereNull('encryption_key_id')
                : $query->where('encryption_key_id', $upload->encryption_key_id))
            ->update(['path' => $caminho, 'encryption_key_id' => $chave]);

        if ($trocou !== 1) {
            // Mudou no meio (outra rodada, exclusão): o novo não vale.
            $this->deleteQuietly((string) $upload->disk, $caminho);

            return 'skipped';
        }

        $this->deleteQuietly((string) $upload->disk, $antigo);

        return 'done';
    }

    /**
     * @return Builder<Upload>
     */
    private function pending(string $keyId): Builder
    {
        return Upload::query()
            ->where('classification', 'confidential')
            ->where(fn (Builder $query) => $query->whereNull('encryption_key_id')->orWhere('encryption_key_id', '!=', $keyId));
    }

    private function deleteQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            // Fica como "arquivo sem registro": sai na limpeza.
            Log::warning('upload.reencrypt_cleanup_failed', ['disk' => $disk, 'exception' => $exception::class]);
        }
    }

    /**
     * Varre os confidenciais de todas as contas.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function system(\Closure $callback): mixed
    {
        return Accounts::asSystem('console:uploads:reencrypt', $callback);
    }
}
