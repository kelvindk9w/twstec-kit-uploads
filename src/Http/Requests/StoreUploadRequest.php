<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de FORMULÁRIO do upload da API v1 (etapa (a) do
 * pipeline). É um corte grosseiro: a validação de SEGURANÇA do conteúdo
 * (magic bytes, polyglot, PDF com script) é do SecureUploadService.
 */
final class StoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorização é dos middlewares (tenant + scope).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Teto grosseiro do formulário: o maior limite por tipo da config.
        // O limite fino POR TIPO é reaplicado pelo SecureUploadService.
        $maxKb = collect((array) config('uploads.types', []))
            ->map(fn (mixed $type): int => (int) (is_array($type) ? ($type['max_kb'] ?? 0) : 0))
            ->max() ?: 1024;

        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.$maxKb],
            'directory' => ['sometimes', 'string', 'alpha_dash', 'max:64'],
        ];
    }
}
