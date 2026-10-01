<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Retention;

use RuntimeException;

/**
 * Tentativa de APAGAR um upload sob guarda legal (o "guardar até" ainda não
 * chegou). Nada foi apagado; a recusa está na trilha de auditoria. A
 * mensagem (traduzida) traz o código público, o prazo e o motivo da guarda.
 */
final class UploadUnderLegalHoldException extends RuntimeException {}
