<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Access;

use RuntimeException;

/**
 * Pedido de URL assinada para um upload que NÃO é da conta atual (de outra
 * conta, ou a foto pessoal de alguém fora do caminho da foto de perfil).
 *
 * Assinar é dar acesso ao arquivo a quem receber a URL. Por isso a URL só sai
 * para o upload da conta atual, ou em modo sistema declarado (o /admin, a
 * leitura restrita da foto de perfil). Ler o registro de outra conta já é
 * impossível pelo escopo da conta; esta recusa cobre o registro que chegou à
 * mão por outro caminho (uma relação carregada antes, um objeto guardado).
 */
final class UploadOutsideAccountException extends RuntimeException
{
    public static function forSigning(): self
    {
        return new self(__('uploads.outside_account'));
    }
}
