<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Enums;

/**
 * Ciclo de vida do registro de upload.
 *
 * Stored: arquivo validado, persistido e disponível. Arquivos REJEITADOS
 * nunca chegam ao banco nem ao disco (política: suspeita = fora).
 */
enum UploadStatus: string
{
    case Stored = 'stored';
    case Deleted = 'deleted';
}
