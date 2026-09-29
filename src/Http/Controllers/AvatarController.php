<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Uploads\Avatar\AvatarService;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Http\Requests\UpdateAvatarRequest;
use Twstec\Kit\Uploads\Http\Resources\UploadResource;

/**
 * Avatar do perfil (web autenticada): POST /settings/avatar.
 *
 * Prova o reuso da MESMA função global de upload fora da API. A foto é da
 * PESSOA (upload pessoal, sem conta — aparece em todas as contas dela), com
 * `created_by` = quem está logado (Services\AvatarService). Restrito a
 * imagens — passam pelo re-encode GD do SecureUploadService.
 *
 * A ROTA é do aplicativo (rota web, no grupo autenticado dele, com o limite
 * `throttle:sensitive` do foundation): o pacote não registra rota web.
 */
final class AvatarController
{
    public function __construct(private readonly AvatarService $avatars) {}

    public function update(UpdateAvatarRequest $request): JsonResponse
    {
        try {
            $upload = $this->avatars->replace($request->user(), $request->file('avatar'));
        } catch (UploadRejectedException $exception) {
            throw ValidationException::withMessages([
                'avatar' => $exception->getMessage(),
            ]);
        }

        return UploadResource::make($upload)
            ->additional(['message' => __('uploads.avatar_updated')])
            ->response()
            ->setStatusCode(201);
    }
}
