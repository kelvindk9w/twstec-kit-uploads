<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * ENTREGA POR URL ASSINADA do disco de uploads — ligada pelo pacote.
 *
 * O arquivo enviado nunca fica em lugar público: o `Upload::url()` devolve
 * uma URL TEMPORÁRIA ASSINADA (validade em `uploads.temporary_url_minutes`),
 * e quem a atende recusa pedido sem assinatura, com assinatura adulterada, de
 * outro caminho ou vencido. No S3/R2 quem assina e confere é o próprio
 * armazenamento. No disco LOCAL é a entrega do Laravel (`serve` no disco:
 * rota `storage.<disco>`, ServeFile), que confere a assinatura do caminho
 * inteiro e a validade — e que só existe se o disco a declarar.
 *
 * O esqueleto de uma aplicação Laravel nova declara `serve => true` no disco
 * `local`, mas nem toda aplicação tem isso: um esqueleto anterior não tem a
 * chave, e o esqueleto do Orchestra Testbench (a "aplicação limpa" da suíte
 * deste pacote) a declara FALSE. Sem a entrega, `temporaryUrl()` não existe
 * para o disco e a URL do upload cai na URL comum, sem assinatura. Por isso o
 * pacote LIGA a entrega assinada no disco padrão de uploads, quando ele é
 * local, sem depender de o aplicativo lembrar — mesmo que o disco a declare
 * desligada: ela só entrega com assinatura válida (disco privado), então
 * ligá-la não expõe nada.
 *
 * O que continua sendo do aplicativo, com aviso no log a cada boot (ver
 * warnings()):
 *
 * - `uploads.protections = false` (UPLOADS_PROTECTIONS=false): o pacote não
 *   mexe no disco (opt-out explícito);
 * - `visibility => 'public'` no disco de uploads: a entrega do Laravel
 *   responde SEM assinatura (o pacote não muda a visibilidade de um disco que
 *   o aplicativo pode usar para outra coisa);
 * - outro disco já entregue no mesmo endereço (`/storage`): ligar derrubaria o
 *   boot do Laravel, então o pacote não liga.
 *
 * Roda antes do boot de qualquer provider (callback `booting`, registrado no
 * register() do provider do pacote): a entrega do Laravel lê a configuração
 * dos discos no boot do FilesystemServiceProvider.
 */
final class SignedDelivery
{
    /**
     * Liga a entrega assinada no disco padrão de uploads, se ele for local e
     * ainda não a tiver. Devolve o nome do disco quando ligou.
     */
    public static function enable(Repository $config): ?string
    {
        if (! self::protectionsEnabled($config)) {
            return null;
        }

        $disk = (string) $config->get('uploads.disk', 'local');
        $definition = $config->get("filesystems.disks.{$disk}");

        if (! is_array($definition) || ($definition['driver'] ?? null) !== 'local' || ($definition['serve'] ?? false) === true) {
            return null;
        }

        // Dois discos entregues no mesmo endereço derrubam o boot do Laravel;
        // se outro disco já responde ali, o pacote não liga (e avisa).
        if (self::uriTakenByAnotherDisk($config, $disk, $definition)) {
            return null;
        }

        $config->set("filesystems.disks.{$disk}.serve", true);

        return $disk;
    }

    /**
     * Os avisos do estado da entrega (um por problema), para o log do boot.
     *
     * @return list<string>
     */
    public static function warnings(Repository $config): array
    {
        if (! self::protectionsEnabled($config)) {
            return ['UPLOADS_PROTECTIONS=false: a entrega por URL assinada do twstec/kit-uploads está DESLIGADA — o pacote não liga a entrega assinada (`serve`) no disco de uploads. Os arquivos só ficam protegidos se a aplicação os entregar por conta própria sem expô-los. Ver Twstec\Kit\Uploads\Support\SignedDelivery.'];
        }

        $disk = (string) $config->get('uploads.disk', 'local');
        $definition = $config->get("filesystems.disks.{$disk}");

        if (! is_array($definition) || ($definition['driver'] ?? null) !== 'local') {
            return [];
        }

        $warnings = [];

        if (($definition['serve'] ?? false) !== true) {
            $warnings[] = "twstec/kit-uploads: o disco de uploads [{$disk}] é local e não tem a entrega assinada (`serve`) — outro disco já é entregue no mesmo endereço, e o pacote não a liga. A URL do upload cai na URL comum do disco, SEM assinatura e sem validade. Dê um `url` próprio a um dos discos ou use um disco que assine (S3/R2). Ver Twstec\\Kit\\Uploads\\Support\\SignedDelivery.";
        }

        if (($definition['visibility'] ?? 'private') === 'public') {
            $warnings[] = "twstec/kit-uploads: o disco de uploads [{$disk}] é PÚBLICO (`visibility => public`) — a entrega do Laravel responde SEM conferir a assinatura. Uploads devem ir para um disco privado. Ver Twstec\\Kit\\Uploads\\Support\\SignedDelivery.";
        }

        return $warnings;
    }

    private static function protectionsEnabled(Repository $config): bool
    {
        return $config->get('uploads.protections', true) !== false;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function uriTakenByAnotherDisk(Repository $config, string $disk, array $definition): bool
    {
        $uri = self::servedUri($definition);

        foreach ((array) $config->get('filesystems.disks', []) as $name => $other) {
            if ($name === $disk || ! is_array($other)) {
                continue;
            }

            if (($other['driver'] ?? null) === 'local' && ($other['serve'] ?? false) && self::servedUri($other) === $uri) {
                return true;
            }
        }

        return false;
    }

    /**
     * O endereço em que o Laravel entrega um disco local (a mesma regra do
     * FilesystemServiceProvider).
     *
     * @param  array<string, mixed>  $definition
     */
    private static function servedUri(array $definition): string
    {
        return isset($definition['url'])
            ? rtrim((string) parse_url((string) $definition['url'], PHP_URL_PATH), '/')
            : '/storage';
    }
}
