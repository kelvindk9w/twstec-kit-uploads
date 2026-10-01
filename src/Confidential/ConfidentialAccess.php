<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Identifiers\UuidColumn;
use Twstec\Kit\Foundation\Logging\CorrelationId;
use Twstec\Kit\Uploads\Access\UploadOutsideAccountException;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * Quem pode abrir um upload CONFIDENCIAL, e o RASTRO de cada acesso — no
 * banco (`audit_events`), com ator, conta, arquivo e contexto (`panel`,
 * `api`, `admin`):
 *
 * - GERAR a URL (Upload::url()) → `upload.confidential_url_issued`. Só para o
 *   upload da conta atual ou em modo sistema declarado (o /admin), e só com
 *   alguém agindo (a pessoa logada, a pessoa por trás da chave de API, o
 *   operador do /admin) — senão, recusa com a linha `denied`. A linha é
 *   gravada ANTES de a URL existir: sem rastro, sem URL.
 * - VISUALIZAR / BAIXAR (a rota `uploads.confidential`) →
 *   `upload.confidential_viewed` / `upload.confidential_downloaded`, gravada
 *   antes do primeiro byte.
 *
 * A URL é ASSINADA e amarra: o upload, a conta em nome da qual foi gerada (ou
 * "sistema", no /admin), quem a gerou, o contexto e se é para baixar. Na
 * entrega, além da assinatura e da validade
 * (`uploads.confidential.url_minutes`, 5 minutos por padrão), a regra é
 * conferida DE NOVO: o upload ainda é daquela conta, quem gerou ainda existe,
 * está ativo e ainda é membro dela (no /admin: ainda é admin). Qualquer
 * diferença → 404 (o mesmo de "não existe": não confirma que o arquivo
 * existe em outra conta) e a recusa na trilha. Assinatura inválida ou vencida
 * → 403, com a recusa na trilha sem confiar em nada do que veio na URL.
 */
final class ConfidentialAccess
{
    public const ROUTE = 'uploads.confidential';

    public const ISSUED = 'upload.confidential_url_issued';

    public const VIEWED = 'upload.confidential_viewed';

    public const DOWNLOADED = 'upload.confidential_downloaded';

    /**
     * Valor de `acc` quando a URL foi gerada em modo sistema (/admin).
     */
    public const SYSTEM = 'system';

    public function __construct(private readonly AuditTrail $trail) {}

    /**
     * @throws UploadOutsideAccountException Upload fora da conta atual.
     *                                       Sem ninguém agindo: 403 (abort), com a recusa na trilha.
     */
    public function issueUrl(Upload $upload, ?DateTimeInterface $expiration = null, bool $download = false): string
    {
        $actor = app(CurrentAccount::class)->actor();
        $context = $this->issuingContext();
        $sistema = Accounts::inSystemMode();
        $tentativa = $sistema ? null : Accounts::current()?->uuid;

        if (! $upload->signableHere()) {
            $this->deny(self::ISSUED, $upload, __('uploads.outside_account'), $context, $actor, $tentativa === null ? null : (string) $tentativa);

            throw UploadOutsideAccountException::forSigning();
        }

        $actorUuid = $actor?->getAttribute('uuid');

        if (! UuidColumn::isValid($actorUuid)) {
            $this->deny(self::ISSUED, $upload, __('uploads.confidential.no_actor'), $context, null, $this->tenantOf($upload));

            abort(403, __('uploads.confidential.no_actor'));
        }

        $expiration ??= now()->addMinutes(max(1, (int) config('uploads.confidential.url_minutes', 5)));

        $this->record(self::ISSUED, $upload, $context, $actor, [
            'disposition' => ['before' => null, 'after' => $download ? 'attachment' : 'inline'],
            'expires_at' => ['before' => null, 'after' => $expiration->format(DATE_ATOM)],
        ]);

        return URL::temporarySignedRoute(self::ROUTE, $expiration, [
            'upload' => (string) $upload->uuid,
            'acc' => $sistema ? self::SYSTEM : (string) Accounts::current()?->uuid,
            'act' => (string) $actorUuid,
            'ctx' => $context->value,
            'dl' => $download ? 1 : 0,
        ]);
    }

    /**
     * Confere a URL da entrega. Devolve o upload e o que a URL autoriza — ou
     * recusa (com a linha `denied`) com 403/404.
     *
     * @return array{upload: Upload, actor: AuthUser&Model, context: AuditContext, download: bool}
     *
     * Recusa: 403/404 (abort), com a recusa na trilha.
     */
    public function authorizeDownload(Request $request, string $uuid): array
    {
        if (! URL::hasValidSignature($request)) {
            // Nada do que veio na URL é confiável: nem ator, nem conta.
            $this->denyRaw(self::VIEWED, UuidColumn::isValid($uuid) ? $uuid : null, __('uploads.confidential.invalid_signature'), AuditContext::Panel, null, null);

            abort(403, __('uploads.confidential.invalid_signature'));
        }

        $context = AuditContext::tryFrom((string) $request->query('ctx')) ?? AuditContext::Panel;
        $download = (string) $request->query('dl') === '1';
        $action = $download ? self::DOWNLOADED : self::VIEWED;
        $conta = (string) $request->query('acc');
        $actorUuid = (string) $request->query('act');

        $upload = UuidColumn::isValid($uuid)
            ? $this->system(fn (): ?Upload => Upload::query()->where('uuid', $uuid)->first())
            : null;

        $actor = UuidColumn::isValid($actorUuid) ? UserModel::query()->where('uuid', $actorUuid)->first() : null;
        $tenant = UuidColumn::isValid($conta) ? $conta : null;

        $motivo = match (true) {
            $upload === null || ! $upload->isConfidential() => __('uploads.confidential.not_found'),
            ! $actor instanceof AuthUser || ! $actor->isActive() => __('uploads.confidential.actor_inactive'),
            ! $this->stillAllowed($upload, $actor, $conta) => __('uploads.confidential.no_longer_allowed'),
            default => null,
        };

        if ($motivo !== null) {
            $this->denyRaw(
                $action,
                $upload?->uuid ?? (UuidColumn::isValid($uuid) ? $uuid : null),
                $motivo,
                $context,
                $actor instanceof AuthUser ? $actor : null,
                $tenant,
            );

            abort(404);
        }

        /** @var Upload $upload */
        /** @var AuthUser&Model $actor */
        return ['upload' => $upload, 'actor' => $actor, 'context' => $context, 'download' => $download];
    }

    /**
     * O registro relido (rotação em andamento: o caminho pode ter mudado).
     */
    public function refresh(Upload $upload): ?Upload
    {
        return $this->system(fn (): ?Upload => Upload::query()->whereKey($upload->getKey())->first());
    }

    /**
     * A entrega vai acontecer: o rastro, antes do primeiro byte.
     */
    public function recordDelivery(Upload $upload, AuthUser $actor, AuditContext $context, bool $download): void
    {
        $this->record($download ? self::DOWNLOADED : self::VIEWED, $upload, $context, $actor, [
            'disposition' => ['before' => null, 'after' => $download ? 'attachment' : 'inline'],
        ]);
    }

    /**
     * A entrega foi recusada depois de autorizada (sem chave, arquivo ausente
     * ou que não decifra).
     */
    public function recordFailedDelivery(Upload $upload, AuthUser $actor, AuditContext $context, bool $download, string $reason): void
    {
        $this->deny($download ? self::DOWNLOADED : self::VIEWED, $upload, $reason, $context, $actor, $this->tenantOf($upload));
    }

    /**
     * A regra de quem gerou, conferida de novo na entrega.
     */
    private function stillAllowed(Upload $upload, AuthUser $actor, string $conta): bool
    {
        if ($conta === self::SYSTEM) {
            // Gerada no /admin: quem gerou ainda entra no painel.
            return (bool) $actor->getAttribute('is_admin');
        }

        if (! UuidColumn::isValid($conta) || $upload->isPersonal() || $upload->getAttribute('account_id') === null) {
            return false;
        }

        $account = Account::query()->where('uuid', $conta)->first();

        return $account instanceof Account
            && (string) $account->getKey() === (string) $upload->getAttribute('account_id')
            && $account->hasMember($actor);
    }

    /**
     * A entrega procura o upload em todas as contas: quem decide se a conta
     * bate é a regra (stillAllowed), não o escopo de uma conta atual — a
     * rota não tem sessão.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function system(\Closure $callback): mixed
    {
        return Accounts::asSystem('uploads:confidential-delivery', $callback);
    }

    private function issuingContext(): AuditContext
    {
        $current = $this->trail->current();

        if ($current !== null) {
            return $current->context;
        }

        if (app(TenantContext::class)->resolved()) {
            return AuditContext::Api;
        }

        return app()->runningInConsole() && ! app()->runningUnitTests() ? AuditContext::Console : AuditContext::Panel;
    }

    /**
     * @param  array<string, array{before?: mixed, after?: mixed}>  $changes
     */
    private function record(string $action, Upload $upload, AuditContext $context, ?AuthUser $actor, array $changes): void
    {
        $this->trail->within($this->scope($context, $actor), fn () => $this->trail->record(
            $action,
            $upload,
            $changes,
            tenantUuid: $this->tenantOf($upload),
        ));
    }

    private function deny(string $action, Upload $upload, string $reason, AuditContext $context, ?AuthUser $actor, ?string $tenantUuid): void
    {
        $this->denyRaw($action, (string) $upload->uuid, $reason, $context, $actor, $tenantUuid);
    }

    private function denyRaw(string $action, ?string $subjectUuid, string $reason, AuditContext $context, ?AuthUser $actor, ?string $tenantUuid): void
    {
        $this->trail->within($this->scope($context, $actor), fn () => $this->trail->denied(
            $action,
            null,
            $reason,
            'upload',
            $tenantUuid,
            $subjectUuid,
        ));
    }

    /**
     * O escopo da linha: o aberto (o /admin abre um em cada chamada) ou um
     * desta requisição, com quem age.
     */
    private function scope(AuditContext $context, ?AuthUser $actor): AuditScope
    {
        $current = $this->trail->current();

        if ($current !== null && $current->context === $context && ($actor === null || $current->actorUuid === $actor->getAttribute('uuid'))) {
            return $current;
        }

        $request = request();

        return new AuditScope(
            context: $context,
            actorUuid: $actor !== null && UuidColumn::isValid($actor->getAttribute('uuid')) ? (string) $actor->getAttribute('uuid') : null,
            actorIsAdmin: $actor !== null ? (bool) $actor->getAttribute('is_admin') : null,
            correlationId: CorrelationId::resolve($request),
            ip: $request->ip(),
            userAgent: Str::limit((string) $request->userAgent(), AuditScope::USER_AGENT_MAX, '') ?: null,
        );
    }

    private function tenantOf(Upload $upload): ?string
    {
        $accountId = $upload->getAttribute('account_id');

        if ($accountId === null) {
            return null;
        }

        $uuid = Account::query()->whereKey($accountId)->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }
}
