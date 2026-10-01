<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential;

use Twstec\Kit\Uploads\Confidential\Exceptions\ConfidentialStorageUnavailableException;

/**
 * As chaves dos uploads confidenciais, lidas da configuração:
 *
 * - `uploads.confidential.key` (UPLOADS_ENCRYPTION_KEY): a ATUAL — cifra todo
 *   arquivo novo e é o destino da rotação;
 * - `uploads.confidential.previous_keys` (UPLOADS_ENCRYPTION_PREVIOUS_KEYS,
 *   separadas por vírgula): as ANTERIORES — só decifram, até a rotação
 *   (`uploads:reencrypt`) terminar de passar os arquivos para a atual.
 *
 * Formato de cada chave: `base64:` + 32 bytes em base64 (o mesmo da
 * APP_KEY), gerada por `php artisan uploads:encryption-key`.
 *
 * FALHA FECHADA: sem a extensão sodium, sem chave atual, com chave em formato
 * inválido ou IGUAL à APP_KEY (ou a uma APP_PREVIOUS_KEYS) — a chave tem de
 * ser própria —, não há chave atual: o upload confidencial é recusado e a
 * entrega responde 503. Em produção, o motivo vai para o log a cada boot
 * (UploadsServiceProvider) — o motivo, nunca a chave.
 */
final class Keyring
{
    public const OK = 'ok';

    public const SODIUM_MISSING = 'sodium_missing';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public const SAME_AS_APP_KEY = 'same_as_app_key';

    /**
     * @param  list<EncryptionKey>  $previous
     * @param  list<string>  $warnings
     */
    private function __construct(
        private readonly ?EncryptionKey $current,
        private readonly array $previous,
        private readonly string $status,
        private readonly array $warnings,
    ) {}

    public static function fromConfig(): self
    {
        if (! self::sodiumAvailable()) {
            return new self(null, [], self::SODIUM_MISSING, []);
        }

        $chavesDaAplicacao = array_filter([
            self::decode((string) config('app.key', '')),
            ...array_map(fn (mixed $chave): ?string => self::decode((string) $chave), (array) config('app.previous_keys', [])),
        ]);

        $avisos = [];
        $anteriores = [];

        foreach (self::list(config('uploads.confidential.previous_keys', [])) as $indice => $texto) {
            $bytes = self::decode($texto);

            if ($bytes === null || in_array($bytes, $chavesDaAplicacao, true)) {
                $avisos[] = 'UPLOADS_ENCRYPTION_PREVIOUS_KEYS: a chave anterior #'.($indice + 1).' está em formato inválido ou é a APP_KEY — foi ignorada.';

                continue;
            }

            $anteriores[] = EncryptionKey::derive($bytes);
        }

        $texto = trim((string) config('uploads.confidential.key', ''));

        if ($texto === '') {
            return new self(null, $anteriores, self::MISSING, $avisos);
        }

        $bytes = self::decode($texto);

        if ($bytes === null) {
            return new self(null, $anteriores, self::INVALID, $avisos);
        }

        if (in_array($bytes, $chavesDaAplicacao, true)) {
            return new self(null, $anteriores, self::SAME_AS_APP_KEY, $avisos);
        }

        return new self(EncryptionKey::derive($bytes), $anteriores, self::OK, $avisos);
    }

    /**
     * Só para os testes simularem um PHP sem a extensão (não há como
     * descarregar uma extensão em tempo de execução). Nulo = a verdade.
     *
     * @internal
     */
    public static ?bool $sodiumOverride = null;

    public static function sodiumAvailable(): bool
    {
        return self::$sodiumOverride ?? (function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            && function_exists('sodium_crypto_kdf_derive_from_key'));
    }

    /**
     * Uma chave nova, no formato da configuração (`base64:...`).
     */
    public static function generate(): string
    {
        return 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES));
    }

    /**
     * Os 32 bytes de uma chave `base64:...` (nulo quando o formato não serve).
     */
    public static function decode(#[\SensitiveParameter] string $configured): ?string
    {
        $configured = trim($configured);

        if (! str_starts_with($configured, 'base64:')) {
            return null;
        }

        $bytes = base64_decode(substr($configured, 7), true);

        return is_string($bytes) && strlen($bytes) === 32 ? $bytes : null;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function available(): bool
    {
        return $this->current !== null;
    }

    /**
     * A chave atual — ou a recusa (falha fechada).
     *
     * @throws ConfidentialStorageUnavailableException
     */
    public function current(): EncryptionKey
    {
        return $this->current ?? throw ConfidentialStorageUnavailableException::because($this->status);
    }

    /**
     * A chave (atual ou anterior) com este identificador, se configurada.
     */
    public function find(string $id): ?EncryptionKey
    {
        foreach ([$this->current, ...$this->previous] as $key) {
            if ($key !== null && hash_equals($key->id, $id)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Identificadores das chaves ANTERIORES configuradas.
     *
     * @return list<string>
     */
    public function previousIds(): array
    {
        return array_values(array_map(fn (EncryptionKey $key): string => $key->id, $this->previous));
    }

    /**
     * Os avisos do boot em produção (sem a chave, só o motivo).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $motivo = match ($this->status) {
            self::SODIUM_MISSING => 'a extensão sodium do PHP (ext-sodium) não está instalada neste servidor — instale-a (no PHP oficial e na imagem do kit ela já vem; em pacotes de distribuição, por exemplo, php8.4-sodium) e reinicie o PHP',
            self::MISSING => 'UPLOADS_ENCRYPTION_KEY não está configurada (gere com `php artisan uploads:encryption-key`)',
            self::INVALID => 'UPLOADS_ENCRYPTION_KEY está em formato inválido (esperado `base64:` + 32 bytes; gere com `php artisan uploads:encryption-key`)',
            self::SAME_AS_APP_KEY => 'UPLOADS_ENCRYPTION_KEY é igual à APP_KEY (ou a uma APP_PREVIOUS_KEYS) — a chave dos uploads tem de ser própria',
            default => null,
        };

        $avisos = $this->warnings;

        if ($motivo !== null) {
            array_unshift($avisos, "twstec/kit-uploads: {$motivo}. Upload CONFIDENCIAL será RECUSADO e a entrega de confidenciais responde 503 (falha fechada). Ver docs/uploads.md, seção \"Uploads confidenciais\".");
        }

        return $avisos;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        $itens = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn (mixed $item): string => trim((string) $item), $itens), fn (string $item): bool => $item !== ''));
    }
}
