# twstec/kit-uploads

> **Parte do [TWS Laravel Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit).** O código, as issues e os
> pull requests ficam no monorepo
> [kelvindk9w/tws-laravel-starter-kit](https://github.com/kelvindk9w/tws-laravel-starter-kit) (pasta `packages/uploads`); este
> repositório é o espelho só-leitura publicado a cada versão.
> Documentação: [docs/](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs) · Segurança:
> [SECURITY.md](SECURITY.md) · Licença: MIT ([LICENSE](LICENSE)).

Uploads seguros do **TWS Laravel Starter Kit**, como pacote Laravel **sem
telas**: validação pelo conteúdo real, reprocessamento de imagem, nome seguro,
entrega por URL assinada, foto de perfil e o `POST /api/v1/uploads`. É a
quarta camada do kit: depende só do [`twstec/kit-accounts`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/accounts), do
[`twstec/kit-auth`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/auth), do [`twstec/kit-foundation`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/foundation) e do
Laravel — não conhece o painel de administração nem a interface, e um teste de
arquitetura na suíte do pacote garante isso.

O **dono do upload é a conta** (como projetos e chaves de API, do
`twstec/kit-accounts`), com o isolamento automático da conta atual; a **foto
de perfil é da pessoa**; excluir a pessoa ou a conta **apaga os arquivos**
(LGPD). Ver [De quem é o upload](#de-quem-é-o-upload).

- **Requisitos:** PHP 8.4+ com as extensões `fileinfo` e `gd`, Laravel 13,
  `twstec/kit-accounts`, `twstec/kit-auth` e `twstec/kit-foundation` 2.x.
- **Licença:** MIT.

## O que o pacote traz

| Peça | O que faz |
| --- | --- |
| `Services\SecureUploadService` | A função global única de upload: validação de segurança, limite por tipo sobre o conteúdo final, nome seguro (uuid + extensão do MIME real), gravação no disco, registro e log estruturado (`upload.stored` / `upload.rejected`) |
| `Services\FileSecurityValidator` | O núcleo da validação pelo conteúdo: executável disfarçado, MIME real (`finfo`) contra a allowlist por tipo, extensão divergente, script embutido (polyglot), PDF com JavaScript ou ação automática, teto de pixels e re-encode da imagem pela GD (falha fechada) |
| `Rules\SafeFile` | A mesma validação como regra, para formulários que não são Form Request (Filament, Livewire) |
| `Models\Upload` | Registro do arquivo aceito (uuid, `UPL-xxxxxx`, MIME real, tamanho, sha256), **da conta** (`BelongsToAccount`: `account_id`, `created_by`), e `url()`, a URL temporária assinada — só para upload da conta atual ou em modo sistema |
| `Concerns\HasAvatar` | Foto de perfil do model de usuário: `avatarUpload()` e `avatarUrl()`, leitura restrita à foto da própria pessoa (coluna `avatar_upload_id`, do aplicativo) |
| `Avatar\AvatarService` | Troca a foto de perfil (upload pessoal, sem conta) — usado pelo perfil e pela rota web do avatar |
| `Erasure\UploadEraser`, `Support\UploadLifecycle`, `Jobs\DeleteUploadFiles` | A exclusão com o dono (LGPD): registros na transação da exclusão, arquivos por job na fila depois do commit, com nova tentativa, e a trilha de auditoria |
| `Console\PruneOrphanUploads` | `uploads:prune-orphans` (`--dry-run`): órfãos antigos, fotos pessoais sem uso e arquivos sem registro — agendado pelo pacote |
| `Access\UploadOutsideAccountException` | A recusa de assinar upload fora da conta atual |
| `Http\Controllers` | `UploadController` (API v1) e `AvatarController` (avatar pela web), com os Form Requests e o `UploadResource` |
| `Http\UploadRoutes` | A rota `POST /api/v1/uploads` |
| `Support\SignedDelivery` | Liga a entrega assinada do Laravel no disco local de uploads |

## Instalação

Pelo Packagist:

```bash
composer require "twstec/kit-uploads:^2.0@beta"   # durante o beta; na 2.0.0 estável, ^2.0
```

Durante o beta, cada pacote do kit que você requerer leva o `@beta` (ou o
projeto declara `"minimum-stability": "beta"` com `"prefer-stable": true`) —
ver [docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md).

**No monorepo** (desenvolvimento do próprio kit), o starter instala o pacote por
*path repository* — como os
outros:

```json
"repositories": [
    {
        "type": "path",
        "url": "../../packages/uploads",
        "options": {
            "versions": { "twstec/kit-uploads": "2.x-dev" },
            "reference": "config"
        }
    }
],
"require": {
    "twstec/kit-uploads": "2.x-dev"
}
```

O `UploadsServiceProvider` é descoberto automaticamente. Depois,
`php artisan migrate`. Para a foto de perfil, o model de usuário do aplicativo
usa a trait `HasAvatar` e ganha a coluna `avatar_upload_id` (chave estrangeira
para `uploads`, `nullOnDelete`) numa migration do próprio aplicativo.

## O que ele instala sozinho

Nenhuma proteção depende de o aplicativo lembrar de chamar algo:

- **Validação pelo conteúdo, limite por tipo, reprocessamento de imagem e
  nome seguro:** moram no serviço e no validador, e valem em qualquer
  chamada — não há opção para desligar.
- **Entrega por URL assinada:** `Upload::url()` devolve URL temporária
  assinada (`uploads.temporary_url_minutes`, 15 minutos). No disco `local`
  quem confere a assinatura e a validade é a entrega do Laravel (`serve`), e
  o **pacote a liga** no disco padrão de uploads quando ele é local — mesmo
  que o disco da aplicação não a declare ou a declare desligada. A assinatura
  cobre o caminho inteiro: a URL de um arquivo não abre o de outro.
- **A rota da API:** `POST /api/v1/uploads` com escopo `uploads:create`, no
  mesmo grupo das rotas v1 do `twstec/kit-accounts` — autenticação por chave
  (`resolve.tenant`, sempre), limite por chave (`throttle:api`) e envelope de
  erro vêm de lá.
- **Configuração padrão** em `config('uploads')`; as chaves de primeiro nível
  do `config/uploads.php` do aplicativo prevalecem
  (`vendor:publish --tag=uploads-config`).
- **Migration** com o **mesmo nome de arquivo** que tinha no aplicativo na 1.x
  (`2026_08_20_300000_create_uploads_table.php`): um banco que já a rodou não
  vê nada pendente; e a que passa os uploads para as contas
  (`2026_09_28_000001_move_uploads_to_accounts.php`, idempotente, reversível,
  em lotes de `UPLOADS_MIGRATION_CHUNK`).
- **Isolamento por conta** no model (o escopo do `twstec/kit-accounts`: sem
  conta atual, erro) e a recusa de assinar URL de upload de outra conta.
- **Exclusão dos arquivos com o dono** (LGPD), ligada aos eventos de exclusão
  do `twstec/kit-accounts` — sem opção para desligar.
- **Comando e agendamento** da limpeza `uploads:prune-orphans`
  (`uploads.prune.schedule`, cron; vazio desliga, com aviso no log a cada
  boot).
- **Traduções** (pt-BR, en, es) das mensagens do upload e de cada motivo de
  recusa (`uploads.*`), sem namespace. **O aplicativo vence** na mesma chave
  (a regra do foundation, `Localization\PackageTranslations`).

Os eventos de log vão para o canal padrão da aplicação; o disco é o que a
aplicação escolher em `UPLOADS_DISK` (padrão: o `local` que toda aplicação
Laravel tem); o limitador é o `api` do foundation, que a aplicação pode
substituir.

**Opt-out** (só explícito): `UPLOADS_PROTECTIONS=false`
(`uploads.protections`) faz o pacote não mexer no disco — e ele grava um aviso
no log a cada boot, em qualquer ambiente. Também avisam a cada boot: disco de
uploads público (`visibility => public`, a entrega responde sem assinatura) e
outro disco já entregue em `/storage` (o pacote não liga a entrega para não
derrubar o boot). Um driver que não sabe assinar cai na URL comum do disco,
como na 1.x.

## Rota da API v1

Prefixo, grupo e nomes seguem os de `api_keys.api.routes` (do
`twstec/kit-accounts`) enquanto `uploads.api.routes` os deixar nulos. Para
registrar você mesmo, desligue o registro automático com
`UPLOADS_API_ROUTES=false` e chame o registro onde quiser:

```php
use Twstec\Kit\Uploads\Http\UploadRoutes;

// routes/api.php (o Laravel já põe o prefixo `api` e o grupo `api`)
UploadRoutes::register(prefix: 'v1', middleware: []);
```

A autenticação por chave entra no grupo em qualquer caso.

## De quem é o upload

**Da conta** (`account_id`), com quem enviou em `created_by`. A web e a API
gravam do mesmo jeito: `SecureUploadService::handle()` grava na conta atual (a
da sessão, a da chave), com quem está agindo — sem conta atual, dá erro antes
de tocar no disco. Toda consulta sai filtrada pela conta atual, e `url()` só
assina upload da conta atual (ou em modo sistema declarado).

A **foto de perfil é da pessoa**: `SecureUploadService::handlePersonal()`
(ou `Avatar\AvatarService`) grava um upload **pessoal**, sem conta, que só a
foto de perfil lê (`HasAvatar`, restrito ao upload que a própria pessoa
aponta: foto pessoal ou upload da conta pessoal dela).

**Excluir a pessoa** apaga a foto dela, as fotos pessoais que enviou e os
uploads das contas que somem junto; os que ela criou em contas de outras
pessoas ficam. **Excluir a conta** apaga os uploads dela. Registro na
transação da exclusão, arquivo por job na fila depois do commit, com nova
tentativa; exclusão recusada ou desfeita não apaga nada. O guia completo
(migração, limpeza, trilha) está em [`docs/uploads.md`](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/uploads.md).

## O que o aplicativo liga

| O quê | Como | Por que não no pacote |
| --- | --- | --- |
| Formulário do perfil e campo de foto do painel `/admin` | Componentes do front sobre `SecureUploadService` e `SafeFile` | São a interface |
| Rota web do avatar | `Route::post('settings/avatar', [AvatarController::class, 'update'])` no grupo autenticado do aplicativo | Rotas web (sessão, verificação de e-mail, limites) são do front, como as do `twstec/kit-auth` |
| Coluna `users.avatar_upload_id` e a trait `HasAvatar` no model de usuário | Migration e model do aplicativo | O model de usuário é do aplicativo |
| Escopo `uploads:create` no catálogo da tela de chaves | `api_keys.scopes_catalog` na cópia do aplicativo | O front decide o que oferece; a API aceita o escopo de qualquer jeito |
| Resources e widgets de uploads no `/admin` | No starter | São do painel de administração |

## Nomes antigos → nomes novos

| Na 1.x | Na 2.0 |
| --- | --- |
| `App\Core\Uploads\…` | `Twstec\Kit\Uploads\…` (o resto do nome não muda) |
| `Upload::owner()`, colunas `user_id` e `tenant_uuid` (até a F8b da 2.0) | `account()`, `creator()`; colunas `account_id`, `created_by`, `personal`, `orphaned_at` |
| `$user->avatar` para ler a foto | `$user->avatarUpload()` / `$user->avatarUrl()` (a relação passa pelo escopo da conta) |
| `config/uploads.php`, migration `create_uploads_table` e `lang/*/uploads.php` no aplicativo | Vêm do pacote (a cópia do aplicativo, se existir, continua valendo) |
| `POST /api/v1/uploads` em `routes/api.php` | Registrada pelo pacote — tire-a de `routes/api.php` |

**Compatibilidade por uma versão.** Os nomes antigos continuam resolvendo, como
apelidos das classes novas (`src/Compat/legacy-aliases.php`): é a mesma classe,
então `instanceof` e type hints aceitam os dois nomes. Isso protege o que está
gravado fora do código — uma rota em cache da 1.x, o snapshot de um componente
Livewire ou um job com um `Upload`, o `use` da trait no model do usuário. Nada
de uploads grava o nome da classe no banco (a trilha de auditoria usa o nome
curto `upload`) nem no disco (o arquivo é achado pelas colunas `disk` e
`path`). **Os apelidos saem na 3.0**: troque os `use` do seu código.

## Testes

A suíte do pacote é isolada do aplicativo (Pest + Orchestra Testbench) e sobe
uma aplicação Laravel **limpa** — o esqueleto do Testbench, o foundation, o
auth, o accounts e este pacote, com um model de usuário mínimo, a rota que o
próprio pacote registra, o disco `local` do Laravel e o **ambiente de uma
aplicação nova** (o `tests/bootstrap.php` apaga do processo toda variável que
não está no `phpunit.xml`).

```bash
composer update
vendor/bin/pest
vendor/bin/pint --test
```

Ela prova que as proteções vêm do pacote (o isolamento por conta — pessoa em
duas contas pela web e pela API, URL assinada só da conta atual, erro sem
conta; a exclusão com os arquivos — banco e disco, recusada e desfeita não
apagam, job depois do commit e com nova tentativa, exclusão de conta; a
limpeza com e sem `--dry-run` e o agendamento; a migração para contas, ida e
volta; conteúdo falso com extensão de
imagem, polyglot, extensão divergente, executável e PDF com JavaScript
recusados; limite por tipo e teto de pixels; imagem reprocessada; URL assinada
que entrega o arquivo e recusa pedido sem assinatura, adulterado, vencido ou
com o caminho de outro dono; entrega ligada num disco que não a tinha;
opt-out e disco público com aviso; credencial e escopo na rota), a fiação do
provider (configuração, migration, rota no grupo do accounts e registro
manual), o canal de log, o disco e o limitador com os do aplicativo vencendo,
as traduções (com o aplicativo vencendo), os apelidos e a arquitetura. Os
testes com as telas, o painel e o banco do aplicativo ficam na suíte do
starter.
