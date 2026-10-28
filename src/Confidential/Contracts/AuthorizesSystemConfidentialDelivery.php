<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential\Contracts;

use Illuminate\Database\Eloquent\Model;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Quem gerou uma URL de upload CONFIDENCIAL em modo sistema (o /admin) ainda
 * pode abri-la? Conferido DE NOVO na entrega (Confidential\ConfidentialAccess),
 * porque a URL vale por alguns minutos e o acesso pode mudar nesse meio-tempo.
 *
 * O padrão do pacote (Confidential\AdministratorFlagDelivery) é a marca
 * `is_admin`. O twstec/kit-admin, quando instalado, registra o dele: a
 * PERMISSÃO do painel para abrir confidencial (`uploads.view_confidential`),
 * a mesma que a ação "abrir" exigiu na geração. Um registro do aplicativo
 * (provider dele, depois dos pacotes) prevalece.
 */
interface AuthorizesSystemConfidentialDelivery
{
    public function allows(AuthUser&Model $actor): bool;
}
