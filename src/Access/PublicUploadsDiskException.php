<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Access;

use RuntimeException;

/**
 * Em produção, o pacote recusa gravar ou entregar upload num disco PÚBLICO
 * (`visibility => public`): ali o arquivo fica acessível sem assinatura (a
 * entrega local do Laravel não confere a assinatura; no S3/R2 o objeto vira
 * público). Ver Twstec\Kit\Uploads\Support\SignedDelivery.
 *
 * A mensagem é o manual de conserto, para quem opera.
 */
final class PublicUploadsDiskException extends RuntimeException
{
    public static function for(string $disk): self
    {
        return new self(sprintf(
            'twstec/kit-uploads: em APP_ENV=production o disco de uploads [%s] é PÚBLICO (`visibility => public`) — o arquivo ficaria acessível '
            .'sem assinatura. Upload recusado. Aponte UPLOADS_DISK para um disco privado (o `local` do Laravel, ou S3/R2 sem visibilidade '
            .'pública); repare que, sem UPLOADS_DISK, vale o FILESYSTEM_DISK. Ver docs/uploads.md.',
            $disk,
        ));
    }
}
