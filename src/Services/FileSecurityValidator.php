<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Services;

use finfo;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;

/**
 * NÚCLEO da validação de segurança de uploads (conteúdo real, nunca extensão ou MIME declarado).
 *
 * Política (lei): o arquivo é o que os MAGIC BYTES dizem, nunca a extensão
 * declarada. Pipeline, nesta ordem — qualquer suspeita = REJEITADO:
 *
 *   1. Executável disfarçado (PE/ELF/shebang) → fora, antes de tudo.
 *   2. MIME REAL via finfo (conteúdo) contra a allowlist por tipo (config).
 *      PDF é só PDF; imagem é só imagem. Qualquer outro MIME → fora.
 *   3. Extensão declarada divergente do conteúdo → fora.
 *   4. Conteúdo varrido por marcadores de script (<?php, <?=, <script,
 *      shebang) → fora (polyglot embutido).
 *   5. PDF com JavaScript/ações automáticas (/JS, /JavaScript, /OpenAction,
 *      /AA) → fora. Política do dono: suspeita = não aceita.
 *   6. Imagens: decodificadas e RE-ENCODADAS via GD (segunda camada — mesmo
 *      que um payload desconhecido escape da varredura, ele não sobrevive à
 *      re-geração dos pixels). Imagem que a GD não decodifica → fora.
 *
 * O retorno traz o CONTEÚDO FINAL a persistir (re-encodado, no caso de
 * imagens), o MIME real e a extensão canônica derivada do MIME — nunca do
 * nome original.
 */
final class FileSecurityValidator
{
    /**
     * Extensões declaradas aceitas por extensão canônica (ex.: o canônico é
     * 'jpg', mas 'jpeg' declarado é legítimo para image/jpeg).
     *
     * @var array<string, list<string>>
     */
    private const EXTENSION_ALIASES = [
        'jpg' => ['jpg', 'jpeg'],
    ];

    /**
     * Marcadores de script/executável embutido (varredura case-insensitive
     * em qualquer tipo). Achou = polyglot/suspeita = rejeitado.
     *
     * @var list<string>
     */
    private const SCRIPT_MARKERS = ['<?php', '<?=', '<script', '#!/'];

    /**
     * Tokens de dicionário PDF que indicam JavaScript ou ações automáticas.
     * Casados com fronteira de delimitador PDF (espaço, '/', '()', '<>', '[]',
     * '%' ou fim) para não confundir com nomes mais longos.
     *
     * @var list<string>
     */
    private const PDF_DANGEROUS_TOKENS = ['/JavaScript', '/JS', '/OpenAction', '/AA'];

    /**
     * @param  string  $content  Bytes brutos do arquivo.
     * @param  string  $declaredExtension  Extensão do nome original (lowercase, sem ponto).
     * @param  list<string>  $allowedTypes  Chaves de config('uploads.types').
     * @return array{mime: string, extension: string, content: string}
     *
     * @throws UploadRejectedException Qualquer divergência ou suspeita.
     */
    public function validate(string $content, string $declaredExtension, array $allowedTypes): array
    {
        if ($content === '') {
            $this->reject('empty');
        }

        $this->ensureNotExecutable($content);

        $mime = $this->detectMime($content);

        [$type, $typeConfig, $canonicalExtension] = $this->matchAllowedType($mime, $allowedTypes);

        $this->ensureExtensionMatches($declaredExtension, $canonicalExtension);

        $this->ensureNoEmbeddedScript($content);

        if ($type === 'pdf') {
            $this->ensurePdfHasNoAutoActions($content);
        }

        if ($type === 'image') {
            $content = $this->reencodeImage($content, $mime, $typeConfig);
        }

        return [
            'mime' => $mime,
            'extension' => $canonicalExtension,
            'content' => $content,
        ];
    }

    /**
     * 1. Magic bytes de executável/script no início do arquivo.
     *    Redundante com a allowlist de MIME de propósito (defesa em profundidade:
     *    se a allowlist for mal configurada um dia, isto continua barrando).
     */
    private function ensureNotExecutable(string $content): void
    {
        if (
            str_starts_with($content, 'MZ')              // PE (Windows .exe/.dll)
            || str_starts_with($content, "\x7fELF")       // ELF (Linux)
            || str_starts_with($content, '#!')            // shebang de script
            || str_starts_with($content, "\xCA\xFE\xBA\xBE") // Java class / Mach-O fat
        ) {
            $this->reject('executable');
        }
    }

    /**
     * 2. MIME real pelo conteúdo (magic bytes), nunca pela extensão.
     */
    private function detectMime(string $content): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! is_string($detected) || $detected === '') {
            $this->reject('mime_undetectable');
        }

        return $detected;
    }

    /**
     * 2b. O MIME real precisa estar na allowlist de algum tipo permitido.
     *     PDF é só PDF; imagem é só imagem; o resto não entra.
     *
     * @param  list<string>  $allowedTypes
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function matchAllowedType(string $mime, array $allowedTypes): array
    {
        /** @var array<string, array<string, mixed>> $types */
        $types = (array) config('uploads.types', []);

        foreach ($allowedTypes as $type) {
            $typeConfig = $types[$type] ?? null;

            if (! is_array($typeConfig)) {
                continue;
            }

            /** @var array<string, string> $mimes */
            $mimes = (array) ($typeConfig['mimes'] ?? []);

            if (array_key_exists($mime, $mimes)) {
                return [$type, $typeConfig, $mimes[$mime]];
            }
        }

        $this->reject('mime_not_allowed');
    }

    /**
     * 3. A extensão declarada no nome original precisa ser compatível com o
     *    conteúdo REAL. Divergência = tentativa de disfarce = rejeitado.
     */
    private function ensureExtensionMatches(string $declaredExtension, string $canonicalExtension): void
    {
        $accepted = self::EXTENSION_ALIASES[$canonicalExtension] ?? [$canonicalExtension];

        if (! in_array($declaredExtension, $accepted, true)) {
            $this->reject('extension_mismatch');
        }
    }

    /**
     * 4. Marcadores de script embutido em qualquer posição do arquivo
     *    (polyglot: começa como imagem/PDF, carrega código executável junto).
     */
    private function ensureNoEmbeddedScript(string $content): void
    {
        $haystack = strtolower($content);

        foreach (self::SCRIPT_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                $this->reject('embedded_script');
            }
        }
    }

    /**
     * 5. PDF com JavaScript embutido ou ações automáticas = REJEITADO
     *    (política: suspeita = não aceita).
     */
    private function ensurePdfHasNoAutoActions(string $content): void
    {
        foreach (self::PDF_DANGEROUS_TOKENS as $token) {
            // Fronteira de delimitador PDF: o token só casa como objeto de
            // dicionário, não como substring de um nome maior (ex.: /AAbc).
            $pattern = '/'.preg_quote($token, '/').'(?=[\s\(\)<>\[\]\/%]|$)/';

            if (preg_match($pattern, $content) === 1) {
                $this->reject('pdf_auto_action');
            }
        }
    }

    /**
     * 6. Re-encode da imagem via GD: decodifica os pixels e re-gera o arquivo
     *    do zero — metadados, comentários e trailing data (onde payloads se
     *    escondem) não sobrevivem. Também PROVA que a imagem é decodificável.
     *    GD indisponível ou falha de decode = falha fechada (rejeita).
     *
     * @param  array<string, mixed>  $typeConfig
     */
    private function reencodeImage(string $content, string $mime, array $typeConfig): string
    {
        $info = @getimagesizefromstring($content);

        if ($info === false) {
            $this->reject('image_undecodable');
        }

        // Teto de pixels antes de decodificar (proteção contra decompression
        // bomb — a GD alocaria largura×altura×4 bytes em memória).
        $maxPixels = (int) ($typeConfig['max_pixels'] ?? 0);

        if ($maxPixels > 0 && ($info[0] * $info[1]) > $maxPixels) {
            $this->reject('image_too_many_pixels');
        }

        if (! function_exists('imagecreatefromstring')) {
            // Falha FECHADA: sem GD não há como reprocessar — não entra.
            $this->reject('image_reencode_unavailable');
        }

        $image = @imagecreatefromstring($content);

        if ($image === false) {
            $this->reject('image_undecodable');
        }

        // Preserva transparência (PNG/WebP com canal alfa).
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $encoded = match ($mime) {
            'image/jpeg' => $this->capture(static fn () => imagejpeg($image, null, 90)),
            'image/png' => $this->capture(static fn () => imagepng($image)),
            'image/webp' => $this->capture(static fn () => imagewebp($image, null, 90)),
            default => null,
        };

        imagedestroy($image);

        if (! is_string($encoded) || $encoded === '') {
            $this->reject('image_reencode_failed');
        }

        return $encoded;
    }

    /**
     * Captura a saída binária de um encoder da GD (imagejpeg/imagepng/...).
     *
     * @return string|null Bytes da imagem re-encodada (null em falha).
     */
    private function capture(callable $encoder): ?string
    {
        ob_start();

        $ok = $encoder();

        $bytes = ob_get_clean();

        return $ok && is_string($bytes) ? $bytes : null;
    }

    /**
     * @throws UploadRejectedException Sempre.
     */
    private function reject(string $reason): never
    {
        throw new UploadRejectedException($reason, __('uploads.rejected.'.$reason));
    }
}
