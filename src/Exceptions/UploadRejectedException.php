<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Exceptions;

use RuntimeException;

/**
 * Upload REJEITADO pela validação de segurança do arquivo.
 *
 * Carrega o MOTIVO interno (chave estável, para logs/telemetria) e a
 * mensagem traduzida exibida ao cliente. O motivo detalhado nunca vaza
 * além do necessário: a mensagem ao usuário é deliberadamente genérica
 * o suficiente para não ensinar o atacante a contornar a validação.
 */
final class UploadRejectedException extends RuntimeException
{
    /**
     * @param  string  $reason  Chave estável do motivo (ex.: 'mime_not_allowed',
     *                          'embedded_script', 'pdf_auto_action').
     */
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
