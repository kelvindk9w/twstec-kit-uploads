<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Access;

use RuntimeException;
use Throwable;

/**
 * O disco do upload não sabe assinar URL temporária (local sem a entrega
 * assinada, driver sem suporte) — e o pacote RECUSA em vez de devolver a URL
 * comum do disco, que entregaria o arquivo sem assinatura e sem validade.
 * Falha fechada: arquivo sem URL é melhor que arquivo exposto.
 *
 * A mensagem é para quem opera (log, job falho), não para o usuário: a API
 * responde com o envelope genérico de erro do servidor.
 */
final class UploadUrlUnavailableException extends RuntimeException
{
    public static function unsignable(string $disk, Throwable $previous): self
    {
        return new self(sprintf(
            'twstec/kit-uploads: o disco [%s] não gerou URL temporária assinada (%s) e o pacote não entrega o arquivo pela URL comum do disco, '
            .'que sairia sem assinatura e sem validade. Use um disco que assine (S3/R2) ou um disco local com a entrega assinada (`serve`, '
            .'que o pacote liga sozinho no disco padrão de uploads). Ver Twstec\\Kit\\Uploads\\Support\\SignedDelivery.',
            $disk,
            $previous->getMessage(),
        ), previous: $previous);
    }
}
