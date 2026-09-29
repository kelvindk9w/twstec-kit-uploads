<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Http\Requests\StoreUploadRequest;
use Twstec\Kit\Uploads\Http\Resources\UploadResource;
use Twstec\Kit\Uploads\Services\SecureUploadService;

/**
 * Endpoint de exemplo da API v1: POST /api/v1/uploads
 * (scope uploads:create). Prova o reuso da função global única de upload
 * (SecureUploadService) no fluxo da API — o registro sai na CONTA da chave,
 * com a pessoa por trás dela em `created_by` (o mesmo jeito da web).
 */
final class UploadController
{
    public function __construct(private readonly SecureUploadService $uploads) {}

    /**
     * POST /api/v1/uploads — validação de formulário (Form Request) →
     * validação de segurança do arquivo → upload → retorno padronizado.
     */
    public function store(StoreUploadRequest $request): JsonResponse
    {
        /** @var array{directory?: string} $data */
        $data = $request->validated();

        try {
            $upload = $this->uploads->handle(
                $request->file('file'),
                directory: $data['directory'] ?? null,
            );
        } catch (UploadRejectedException $exception) {
            // Rejeição de segurança = erro de validação do campo (422).
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return UploadResource::make($upload)
            ->additional(['message' => __('uploads.stored')])
            ->response()
            ->setStatusCode(201);
    }
}
