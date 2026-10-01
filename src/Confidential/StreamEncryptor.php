<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential;

use Closure;
use LogicException;

/**
 * O lado que CIFRA de um arquivo confidencial (ver StreamCipher): recebe o
 * texto claro em pedaços de qualquer tamanho, guarda no máximo um bloco e
 * escreve cada bloco cifrado assim que ele fecha. finish() cifra o que sobrou
 * com a marca FINAL — sem ele, o arquivo não decifra (truncado).
 */
final class StreamEncryptor
{
    private string $buffer = '';

    private bool $finished = false;

    /**
     * @param  Closure(string): void  $write
     */
    public function __construct(
        private string $state,
        private readonly string $additional,
        private readonly int $chunkBytes,
        private readonly Closure $write,
    ) {}

    public function write(#[\SensitiveParameter] string $plain): void
    {
        if ($this->finished) {
            throw new LogicException('Cifra já finalizada.');
        }

        $this->buffer .= $plain;

        while (strlen($this->buffer) > $this->chunkBytes) {
            $bloco = substr($this->buffer, 0, $this->chunkBytes);
            $this->buffer = substr($this->buffer, $this->chunkBytes);

            ($this->write)(sodium_crypto_secretstream_xchacha20poly1305_push(
                $this->state,
                $bloco,
                $this->additional,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            ));
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        ($this->write)(sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state,
            $this->buffer,
            $this->additional,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        ));

        sodium_memzero($this->state);
        $this->buffer = '';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['state' => '[REDACTED]'];
    }
}
