<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential;

use LogicException;

/**
 * UMA chave de cifra dos uploads confidenciais, já DERIVADA da chave
 * configurada (UPLOADS_ENCRYPTION_KEY ou uma das anteriores).
 *
 * Da chave configurada (32 bytes) saem, por derivação com contexto próprio
 * (sodium_crypto_kdf_derive_from_key, contexto `TWSUPLD1`):
 * - a chave que cifra (subchave 1, 32 bytes) — a configurada nunca cifra
 *   nada diretamente;
 * - o IDENTIFICADOR DA VERSÃO (subchave 2, os 8 primeiros bytes, em hex):
 *   gravado no cabeçalho de cada arquivo e na coluna
 *   `uploads.encryption_key_id`. Não revela nada da chave (é saída de uma
 *   função de derivação) e não depende de alguém numerar versões à mão.
 *
 * Nunca aparece em log, dump nem fila: __debugInfo() esconde o material e a
 * serialização é recusada.
 */
final class EncryptionKey
{
    public const KDF_CONTEXT = 'TWSUPLD1';

    private function __construct(
        public readonly string $id,
        private readonly string $material,
    ) {}

    /**
     * @param  string  $configured  Os 32 bytes da chave configurada (já decodificados).
     */
    public static function derive(#[\SensitiveParameter] string $configured): self
    {
        if (strlen($configured) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new LogicException('A chave de cifra dos uploads precisa ter 32 bytes.');
        }

        $material = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 1, self::KDF_CONTEXT, $configured);
        $identificador = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_KDF_BYTES_MIN, 2, self::KDF_CONTEXT, $configured);

        return new self(bin2hex(substr($identificador, 0, 8)), $material);
    }

    /**
     * O material da cifra — só para Confidential\StreamCipher.
     *
     * @internal
     */
    public function material(): string
    {
        return $this->material;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'material' => '[REDACTED]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Chave de cifra não é serializável (não vai para fila, cache nem log).');
    }
}
