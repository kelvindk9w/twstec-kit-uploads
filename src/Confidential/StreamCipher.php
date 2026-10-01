<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential;

use Closure;
use LogicException;
use RuntimeException;
use Twstec\Kit\Uploads\Confidential\Exceptions\UndecryptableUploadException;

/**
 * O FORMATO do arquivo confidencial e a cifra em FLUXO (libsodium
 * secretstream: XChaCha20-Poly1305, AEAD), sem nunca precisar do arquivo
 * inteiro em memória — nem do texto claro em disco temporário.
 *
 * Arquivo (versão 1):
 *
 *     "TWSUPENC"            8 bytes  marca do formato
 *     0x01                  1 byte   versão do formato
 *     id da chave           8 bytes  (EncryptionKey::id, em binário)
 *     tamanho do bloco      4 bytes  bytes de texto claro por bloco (big-endian)
 *     cabeçalho do fluxo   24 bytes  (nonce aleatório do secretstream)
 *     blocos               ...      cada um = bloco cifrado + 17 bytes de
 *                                   autenticação; o último leva a marca FINAL
 *
 * Cada bloco é autenticado com DADOS ADICIONAIS = os 21 primeiros bytes
 * (marca, versão, chave, tamanho do bloco) + o VÍNCULO (o uuid do registro do
 * upload). Por isso:
 * - trocar um bit em qualquer lugar → não decifra;
 * - cortar o fim (sem o bloco FINAL), acrescentar depois dele, reordenar ou
 *   repetir blocos → não decifra (o secretstream encadeia os blocos);
 * - pôr no lugar o objeto cifrado de OUTRO upload (mesmo da mesma chave) →
 *   não decifra (o vínculo é outro).
 *
 * Bloco adulterado no meio de uma entrega: os blocos anteriores já saíram
 * (cada um autenticado por si), o resto não sai e a entrega é cortada — o
 * cliente vê o tamanho incompleto (Content-Length).
 */
final class StreamCipher
{
    public const MAGIC = 'TWSUPENC';

    public const VERSION = 1;

    /**
     * Tamanho do prefixo autenticado (marca + versão + chave + bloco).
     */
    public const PREFIX_BYTES = 21;

    public const DEFAULT_CHUNK_BYTES = 65536;

    /**
     * Teto do tamanho de bloco aceito na leitura (contra cabeçalho forjado
     * que pediria memória demais).
     */
    public const MAX_CHUNK_BYTES = 8388608;

    /**
     * Começa um arquivo cifrado: escreve o cabeçalho em `$write` e devolve o
     * cifrador (write() pedaços de texto claro, finish() no fim).
     *
     * @param  Closure(string): void  $write
     */
    public static function encryptor(EncryptionKey $key, string $binding, Closure $write, int $chunkBytes = self::DEFAULT_CHUNK_BYTES): StreamEncryptor
    {
        if ($chunkBytes < 1 || $chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new LogicException('Tamanho de bloco inválido para a cifra dos uploads.');
        }

        $prefix = self::MAGIC.chr(self::VERSION).hex2bin($key->id).pack('N', $chunkBytes);

        [$state, $header] = self::initPush($key);

        $write($prefix.$header);

        return new StreamEncryptor($state, $prefix.$binding, $chunkBytes, $write);
    }

    /**
     * Decifra o arquivo lido por `$read` (devolve até N bytes; '' no fim),
     * entregando o texto claro em pedaços a `$sink`. Lança antes de entregar
     * qualquer pedaço que não autentica.
     *
     * @param  Closure(int): string  $read
     * @param  Closure(string): void  $sink
     * @return string O id da chave que decifrou.
     *
     * @throws UndecryptableUploadException
     */
    public static function decrypt(Keyring $keyring, string $binding, Closure $read, Closure $sink): string
    {
        $prefix = self::readExactly($read, self::PREFIX_BYTES);

        if (strlen($prefix) !== self::PREFIX_BYTES || ! str_starts_with($prefix, self::MAGIC)) {
            throw new UndecryptableUploadException('not_encrypted');
        }

        if (ord($prefix[8]) !== self::VERSION) {
            throw new UndecryptableUploadException('unknown_version');
        }

        $keyId = bin2hex(substr($prefix, 9, 8));
        $chunkBytes = (int) unpack('N', substr($prefix, 17, 4))[1];

        if ($chunkBytes < 1 || $chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new UndecryptableUploadException('bad_header');
        }

        $key = $keyring->find($keyId) ?? throw new UndecryptableUploadException('unknown_key');

        $header = self::readExactly($read, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new UndecryptableUploadException('truncated');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key->material());
        $additional = $prefix.$binding;
        $frameBytes = $chunkBytes + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $final = false;

        try {
            while (true) {
                $frame = self::readExactly($read, $frameBytes);

                if ($frame === '') {
                    break;
                }

                if ($final) {
                    throw new UndecryptableUploadException('data_after_final');
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame, $additional);

                if ($result === false) {
                    throw new UndecryptableUploadException('authentication_failed');
                }

                [$plain, $tag] = $result;

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $final = true;
                }

                if ($plain !== '') {
                    $sink($plain);
                }
            }
        } finally {
            sodium_memzero($state);
        }

        if (! $final) {
            throw new UndecryptableUploadException('truncated');
        }

        return $keyId;
    }

    /**
     * Lê exatamente `$bytes` (menos só no fim do arquivo) — leituras de rede
     * devolvem pedaços menores.
     *
     * @param  Closure(int): string  $read
     */
    public static function readExactly(Closure $read, int $bytes): string
    {
        $buffer = '';

        while (strlen($buffer) < $bytes) {
            $piece = $read($bytes - strlen($buffer));

            if ($piece === '') {
                break;
            }

            $buffer .= $piece;
        }

        return $buffer;
    }

    /**
     * Leitor de um recurso de stream (falha de leitura = erro, não fim).
     *
     * @param  resource  $stream
     * @return Closure(int): string
     */
    public static function streamReader($stream): Closure
    {
        return static function (int $bytes) use ($stream): string {
            if (feof($stream)) {
                return '';
            }

            $piece = fread($stream, $bytes);

            if ($piece === false) {
                throw new RuntimeException('Falha ao ler o arquivo do armazenamento.');
            }

            return $piece;
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function initPush(EncryptionKey $key): array
    {
        return sodium_crypto_secretstream_xchacha20poly1305_init_push($key->material());
    }
}
