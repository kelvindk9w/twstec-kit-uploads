<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential;

use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Uploads\Confidential\Contracts\AuthorizesSystemConfidentialDelivery;

/**
 * O critério padrão da entrega de confidencial gerada em modo sistema: quem
 * gerou ainda tem a marca `is_admin`. Sem o twstec/kit-admin não há papéis do
 * painel a conferir; com ele, vale o critério dele (a permissão).
 */
final class AdministratorFlagDelivery implements AuthorizesSystemConfidentialDelivery
{
    public function allows(AuthUser&Model $actor): bool
    {
        return (bool) $actor->getAttribute('is_admin');
    }
}
