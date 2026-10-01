<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upload ou entrega CONFIDENCIAL sem chave de cifra utilizável (ausente,
 * inválida, igual à APP_KEY, sodium ausente). FALHA FECHADA: nada é gravado em
 * claro e nada é entregue. Responde 503 — é falta de configuração do
 * servidor, não erro de quem enviou. A mensagem nunca traz a chave.
 */
final class ConfidentialStorageUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        // Sem a extensão, a mensagem diz isso (a ação é instalar, não gerar
        // chave); nos outros casos, que a cifra não está configurada.
        parent::__construct(__($reason === 'sodium_missing' ? 'uploads.confidential.unavailable_sodium' : 'uploads.confidential.unavailable'));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $this->getMessage()], 503);
        }

        return response($this->getMessage(), 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
