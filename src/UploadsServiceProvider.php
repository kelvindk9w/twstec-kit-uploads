<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Foundation\Localization\PackageTranslations;
use Twstec\Kit\Uploads\Confidential\ConfidentialAccess;
use Twstec\Kit\Uploads\Confidential\Keyring;
use Twstec\Kit\Uploads\Console\EraseExpiredHolds;
use Twstec\Kit\Uploads\Console\GenerateEncryptionKey;
use Twstec\Kit\Uploads\Console\PruneOrphanUploads;
use Twstec\Kit\Uploads\Console\ReencryptConfidentialUploads;
use Twstec\Kit\Uploads\Retention\LegalHoldDeletionCheck;
use Twstec\Kit\Uploads\Support\SignedDelivery;
use Twstec\Kit\Uploads\Support\UploadLifecycle;

/**
 * O que o pacote de uploads instala numa aplicação Laravel — sozinho, sem a
 * aplicação precisar lembrar de chamar nada:
 *
 * - a configuração padrão (`config('uploads')`: disco, diretório, validade da
 *   URL assinada, tipos permitidos e o catálogo de tipos com MIME real,
 *   limite de tamanho e teto de pixels), a migration da tabela `uploads` (com
 *   o MESMO nome de arquivo que tinha no aplicativo) e as traduções do
 *   domínio (`uploads.*`), com o aplicativo vencendo na mesma chave;
 * - a ENTREGA POR URL ASSINADA no disco padrão de uploads, quando ele é local
 *   (ver Support\SignedDelivery): a rota do Laravel que entrega o arquivo só
 *   responde a URL assinada e dentro da validade;
 * - a rota da API v1 `POST /api/v1/uploads`, no mesmo grupo das rotas v1 do
 *   twstec/kit-accounts, desligável para a aplicação registrar ela mesma (ver
 *   Http\UploadRoutes);
 * - a EXCLUSÃO DOS ARQUIVOS com o dono (LGPD — Support\UploadLifecycle):
 *   excluir a pessoa apaga a foto dela e os uploads das contas que somem
 *   junto; excluir a conta apaga os uploads dela — registro na transação,
 *   arquivo por job na fila depois do commit. Sem opção para desligar;
 * - o comando `uploads:prune-orphans` e o AGENDAMENTO dele
 *   (`uploads.prune.schedule`, cron; vazio desliga, com aviso no log a cada
 *   boot);
 * - os UPLOADS CONFIDENCIAIS: a rota de entrega que decifra
 *   (`uploads.confidential`, limitada por IP), os comandos da chave
 *   (`uploads:encryption-key`, `uploads:reencrypt`) e, EM PRODUÇÃO, o aviso
 *   no log a cada boot quando não há chave utilizável (o upload confidencial
 *   é recusado — falha fechada);
 * - a RETENÇÃO LEGAL: o verificador de impedimento de exclusão no pacote de
 *   contas (Retention\LegalHoldDeletionCheck — só recusa com
 *   `uploads.legal_hold.blocks_deletion`) e o comando agendado
 *   `uploads:erase-expired-holds` (`uploads.legal_hold.schedule`).
 *
 * As regras que moram no domínio continuam lá e não dependem de provider nem
 * de config: a validação pelo CONTEÚDO (magic bytes, allowlist por tipo,
 * extensão divergente, executável, script embutido, PDF com ações
 * automáticas), o reprocessamento da imagem, o limite de tamanho por tipo
 * sobre o conteúdo final e o nome seguro (FileSecurityValidator +
 * SecureUploadService, a função global única de upload).
 *
 * Opt-out da entrega assinada: `uploads.protections = false`
 * (UPLOADS_PROTECTIONS=false) — com aviso no log a cada boot, em qualquer
 * ambiente. Disco local que a aplicação declarou sem entrega (`serve =>
 * false`) ou público também deixa aviso.
 *
 * Não há provider antigo: na 1.x o módulo App\Core\Uploads não tinha provider
 * (a configuração e a migration vinham do aplicativo).
 */
final class UploadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->path('config/uploads.php'), 'uploads');

        // Antes do boot de QUALQUER provider — o FilesystemServiceProvider lê
        // os discos no boot dele para registrar a rota de entrega —, mas depois
        // de toda a configuração estar no lugar (a dos arquivos e a que outro
        // provider ajustar no register).
        $this->app->booting(function (): void {
            SignedDelivery::enable($this->app['config']);
        });

        PackageTranslations::register($this->app, $this->path('lang'));

        // Um só por processo: guarda, entre o `deleting` e o `deleted` da
        // pessoa, o que sai junto com ela.
        $this->app->singleton(UploadLifecycle::class);
    }

    public function boot(): void
    {
        foreach (SignedDelivery::warnings($this->app['config']) as $warning) {
            Log::warning($warning);
        }

        // Sem chave dos confidenciais em produção: o motivo (nunca a chave)
        // no log a cada boot. O upload confidencial é recusado de qualquer
        // jeito (falha fechada); o aviso é para alguém ver antes do usuário.
        if ($this->app->isProduction()) {
            foreach (Keyring::fromConfig()->warnings() as $warning) {
                Log::warning($warning);
            }
        }

        UploadLifecycle::register($this->app['events']);

        // A guarda legal no mecanismo de impedimentos de exclusão do pacote
        // de contas (só recusa com `uploads.legal_hold.blocks_deletion`).
        $this->callAfterResolving(DeletionImpediments::class, static function (DeletionImpediments $impediments): void {
            $impediments->register(LegalHoldDeletionCheck::class);
        });

        RateLimiter::for(ConfidentialAccess::ROUTE, static fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('uploads.confidential.rate_limit', 60)))->by((string) $request->ip()));

        $this->registerPruneSchedule();
        $this->registerLegalHoldSchedule();

        // A migration roda direto daqui, com o MESMO nome de arquivo que tinha
        // quando morava no aplicativo: um banco que já a rodou não vê nada
        // pendente, e um banco novo a roda na mesma ordem.
        $this->loadMigrationsFrom($this->path('database/migrations'));

        if (config('uploads.api.routes.enabled', true) !== false) {
            $this->loadRoutesFrom($this->path('routes/api.php'));
        }

        // A entrega dos confidenciais: sempre (é o único caminho deles).
        $this->loadRoutesFrom($this->path('routes/web.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneOrphanUploads::class,
                GenerateEncryptionKey::class,
                ReencryptConfidentialUploads::class,
                EraseExpiredHolds::class,
            ]);

            $this->publishes([
                $this->path('config/uploads.php') => config_path('uploads.php'),
            ], 'uploads-config');
        }
    }

    /**
     * A limpeza diária dos uploads sem dono entra no agendador da aplicação
     * sozinha. Cron vazio = desligada, com aviso no log a cada boot (o
     * comando continua disponível).
     */
    private function registerPruneSchedule(): void
    {
        $cron = trim((string) config('uploads.prune.schedule', ''));

        if ($cron === '' || in_array(strtolower($cron), ['false', 'off', '0'], true)) {
            Log::warning('uploads.prune.schedule vazio: a limpeza agendada dos uploads sem dono (uploads:prune-orphans) está DESLIGADA. Órfãos, fotos sem uso e arquivos sem registro só saem rodando o comando à mão.');

            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($cron): void {
            $schedule->command('uploads:prune-orphans')
                ->cron($cron)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    /**
     * O apagamento dos uploads cuja guarda legal venceu entra no agendador
     * sozinho. Cron vazio = desligado, com aviso a cada boot.
     */
    private function registerLegalHoldSchedule(): void
    {
        $cron = trim((string) config('uploads.legal_hold.schedule', ''));

        if ($cron === '' || in_array(strtolower($cron), ['false', 'off', '0'], true)) {
            Log::warning('uploads.legal_hold.schedule vazio: o apagamento agendado dos uploads com a guarda legal vencida (uploads:erase-expired-holds) está DESLIGADO. Eles só saem rodando o comando à mão.');

            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($cron): void {
            $schedule->command('uploads:erase-expired-holds')
                ->cron($cron)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__).'/'.$relative;
    }
}
