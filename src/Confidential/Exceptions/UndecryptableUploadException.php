<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential\Exceptions;

use RuntimeException;

/**
 * O objeto cifrado não decifra: cabeçalho que não é do formato, chave que não
 * está mais configurada, bloco adulterado, arquivo truncado ou trocado por o de
 * outro upload (a cifra amarra cada arquivo ao uuid do seu registro). Nenhum
 * trecho de conteúdo não autenticado é devolvido. O motivo (`reason`) é
 * estável e não traz conteúdo nem chave.
 */
final class UndecryptableUploadException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Upload confidencial não decifra ({$reason}).");
    }
}
