<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de FORMULÁRIO do avatar do perfil (web autenticada).
 * Avatar é SÓ imagem — o re-encode GD do SecureUploadService elimina
 * qualquer payload embutido antes de persistir.
 */
final class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorização é do middleware auth (rota protegida).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) data_get(config('uploads.types'), 'image.max_kb', 5120);

        return [
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ];
    }
}
