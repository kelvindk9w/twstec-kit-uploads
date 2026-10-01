<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential;

use Closure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Twstec\Kit\Uploads\Confidential\Exceptions\ConfidentialStorageUnavailableException;
use Twstec\Kit\Uploads\Confidential\Exceptions\UndecryptableUploadException;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * Onde o upload confidencial encontra o armazenamento: cifra ANTES de gravar
 * e decifra em fluxo ao ler. O armazenamento (disco local, S3/R2) só vê o
 * objeto cifrado (formato em StreamCipher).
 *
 * - O texto claro nunca vai para arquivo temporário: a cifra trabalha em
 *   blocos (64 KB) e o que vai para o temporário (php://temp) é o objeto JÁ
 *   cifrado, antes de subir.
 * - A leitura vem do armazenamento em stream (readStream) e sai em pedaços.
 * - A recifragem (rotação) lê o objeto antigo, decifra e cifra de novo bloco a
 *   bloco, conferindo o sha256 do texto claro com o do registro antes de o
 *   objeto novo valer.
 */
final class ConfidentialStorage
{
    /**
     * Teto de memória do temporário do objeto CIFRADO antes de subir (acima
     * disso o PHP passa para arquivo — conteúdo já cifrado).
     */
    private const TEMP_MEMORY_BYTES = 2097152;

    /**
     * Cifra o conteúdo e grava no disco. Devolve o id da chave usada.
     *
     * @throws ConfidentialStorageUnavailableException Sem chave utilizável — nada é gravado.
     */
    public function put(string $disk, string $path, #[\SensitiveParameter] string $plain, string $binding): string
    {
        $key = Keyring::fromConfig()->current();

        $this->upload($disk, $path, $key, $binding, function (StreamEncryptor $encryptor) use ($plain): void {
            $tamanho = strlen($plain);

            for ($offset = 0; $offset < $tamanho; $offset += StreamCipher::DEFAULT_CHUNK_BYTES) {
                $encryptor->write(substr($plain, $offset, StreamCipher::DEFAULT_CHUNK_BYTES));
            }
        });

        return $key->id;
    }

    /**
     * Decifra o arquivo do upload, entregando o texto claro em pedaços.
     *
     * @param  Closure(string): void  $sink
     *
     * @throws UndecryptableUploadException
     * @throws RuntimeException Objeto ausente no armazenamento.
     */
    public function stream(Upload $upload, Closure $sink): void
    {
        $this->readInto((string) $upload->disk, (string) $upload->path, (string) $upload->uuid, Keyring::fromConfig(), $sink);
    }

    /**
     * O objeto do upload existe no armazenamento?
     */
    public function exists(Upload $upload): bool
    {
        return Storage::disk((string) $upload->disk)->exists((string) $upload->path);
    }

    /**
     * Recifra o arquivo do upload com a chave atual, num caminho NOVO (o
     * antigo continua lá, legível, até quem chamou trocar o registro).
     * Confere o sha256 do texto claro antes de devolver.
     *
     * @return array{path: string, key_id: string}
     *
     * @throws ConfidentialStorageUnavailableException
     * @throws UndecryptableUploadException
     */
    public function reencrypt(Upload $upload, string $newPath): array
    {
        $keyring = Keyring::fromConfig();
        $key = $keyring->current();
        $hash = hash_init('sha256');
        $disk = (string) $upload->disk;

        $this->upload($disk, $newPath, $key, (string) $upload->uuid, function (StreamEncryptor $encryptor) use ($upload, $keyring, $hash, $disk): void {
            $this->readInto($disk, (string) $upload->path, (string) $upload->uuid, $keyring, function (string $plain) use ($encryptor, $hash): void {
                hash_update($hash, $plain);
                $encryptor->write($plain);
            });
        }, onFailureDelete: true);

        if (! hash_equals((string) $upload->sha256, hash_final($hash))) {
            Storage::disk($disk)->delete($newPath);

            throw new UndecryptableUploadException('checksum_mismatch');
        }

        return ['path' => $newPath, 'key_id' => $key->id];
    }

    /**
     * @param  Closure(StreamEncryptor): void  $feed
     */
    private function upload(string $disk, string $path, EncryptionKey $key, string $binding, Closure $feed, bool $onFailureDelete = false): void
    {
        $temp = fopen('php://temp/maxmemory:'.self::TEMP_MEMORY_BYTES, 'w+b');

        if ($temp === false) {
            throw new RuntimeException('Não foi possível preparar o arquivo cifrado.');
        }

        try {
            $encryptor = StreamCipher::encryptor($key, $binding, static function (string $bytes) use ($temp): void {
                if (fwrite($temp, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Não foi possível preparar o arquivo cifrado.');
                }
            });

            $feed($encryptor);
            $encryptor->finish();

            rewind($temp);

            $gravou = false;

            try {
                $gravou = Storage::disk($disk)->writeStream($path, $temp);
            } finally {
                if (! $gravou && $onFailureDelete) {
                    Storage::disk($disk)->delete($path);
                }
            }

            if (! $gravou) {
                throw new RuntimeException('Falha ao persistir o arquivo no armazenamento.');
            }
        } finally {
            fclose($temp);
        }
    }

    /**
     * @param  Closure(string): void  $sink
     */
    private function readInto(string $disk, string $path, string $binding, Keyring $keyring, Closure $sink): void
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Arquivo confidencial ausente no armazenamento.');
        }

        try {
            StreamCipher::decrypt($keyring, $binding, StreamCipher::streamReader($stream), $sink);
        } finally {
            fclose($stream);
        }
    }
}
