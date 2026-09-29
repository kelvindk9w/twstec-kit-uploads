<?php

declare(strict_types=1);

// =============================================================================
// Nomes antigos (1.x) → nomes novos, por UMA versão (2.x).
//
// Na 1.x o módulo de uploads morava no aplicativo, em App\Core\Uploads\…; na
// 2.0 ele é deste pacote, em Twstec\Kit\Uploads\…. O nome antigo continua
// resolvendo porque ele pode estar gravado fora do código:
//
//   - rota em cache da 1.x (os controllers do upload da API e do avatar);
//   - snapshot de componente Livewire aberto no navegador durante o deploy
//     (uma propriedade com um Upload guarda a classe) e payload de fila
//     serializado com um Upload;
//   - código do projeto que ainda não trocou o `use` (o model do usuário que
//     compõe a trait HasAvatar, a regra SafeFile em formulários, o
//     SecureUploadService).
//
// Não há morph nem `*_type` com a classe: a trilha de auditoria grava o nome
// curto estável (`upload`), e o arquivo no disco é localizado pelas colunas
// `disk` e `path` do registro, que não mudam.
//
// O alias é PREGUIÇOSO: nada é carregado até alguém pedir um nome antigo. O
// autoloader do Composer tenta primeiro; só quando ele não acha é que este
// entra, carrega a classe nova e registra o nome antigo como apelido dela — a
// MESMA classe, então `instanceof` e type hints aceitam os dois nomes.
//
// O código do kit usa só os nomes novos (um teste de arquitetura do starter
// garante). Estes apelidos saem na 3.0.
// =============================================================================

spl_autoload_register(static function (string $class): void {
    // Só as partes que existiam no módulo da 1.x: as peças novas do pacote
    // (provider, rotas, entrega assinada) nunca tiveram nome antigo.
    static $legacy = ['Concerns\\', 'Enums\\', 'Exceptions\\', 'Http\\Controllers\\', 'Http\\Requests\\', 'Http\\Resources\\', 'Models\\', 'Rules\\', 'Services\\'];

    $old = 'App\\Core\\Uploads\\';

    if (! str_starts_with($class, $old)) {
        return;
    }

    $relative = substr($class, strlen($old));

    foreach ($legacy as $part) {
        if (str_starts_with($relative, $part)) {
            $target = 'Twstec\\Kit\\Uploads\\'.$relative;

            if (class_exists($target) || interface_exists($target) || trait_exists($target) || enum_exists($target)) {
                class_alias($target, $class);
            }

            return;
        }
    }
});
