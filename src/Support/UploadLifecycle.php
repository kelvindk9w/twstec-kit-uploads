<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Twstec\Kit\Accounts\Account\Events\AccountDeleting;
use Twstec\Kit\Accounts\Account\Events\PersonDeleted;
use Twstec\Kit\Accounts\Account\Events\PersonDeleting;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Uploads\Erasure\UploadEraser;
use WeakMap;

/**
 * Liga a exclusão dos uploads aos eventos de exclusão do twstec/kit-accounts
 * — instalado pelo pacote, sem o aplicativo lembrar de nada:
 *
 * - PersonDeleting (a regra das contas não recusou): LÊ o que vai sair — a
 *   foto dela, as fotos pessoais que enviou e os uploads das contas que somem
 *   junto. Não muda nada: outra guarda ainda pode recusar a exclusão.
 * - PersonDeleted (a pessoa saiu): apaga os registros lidos e manda apagar os
 *   arquivos depois do commit. Exclusão recusada nunca chega aqui.
 * - AccountDeleting (exclusão de conta, dentro da transação): apaga os
 *   uploads da conta do mesmo jeito.
 *
 * Sem opção para desligar: é o que a LGPD pede quando o titular sai.
 */
final class UploadLifecycle
{
    /**
     * O que cada pessoa em exclusão vai levar junto.
     *
     * @var WeakMap<AuthUser, list<array{id: int, disk: string, path: string}>>
     */
    private WeakMap $pending;

    public function __construct(private readonly UploadEraser $eraser)
    {
        $this->pending = new WeakMap;
    }

    public static function register(Dispatcher $events): void
    {
        $events->listen(PersonDeleting::class, static function (PersonDeleting $event): void {
            app(self::class)->personDeleting($event);
        });

        $events->listen(PersonDeleted::class, static function (PersonDeleted $event): void {
            app(self::class)->personDeleted($event);
        });

        $events->listen(AccountDeleting::class, static function (AccountDeleting $event): void {
            app(UploadEraser::class)->eraseAccount($event->account);
        });
    }

    public function personDeleting(PersonDeleting $event): void
    {
        $this->pending[$event->user] = $this->eraser->snapshotForPerson($event->user, $event->vanishingAccountIds);
    }

    public function personDeleted(PersonDeleted $event): void
    {
        $snapshot = $this->pending[$event->user] ?? [];

        unset($this->pending[$event->user]);

        $this->eraser->eraseForPerson($event->user->getKey(), $snapshot);
    }
}
