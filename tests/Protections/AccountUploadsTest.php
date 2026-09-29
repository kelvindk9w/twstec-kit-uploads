<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\CrossAccountWriteException;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Uploads\Access\UploadOutsideAccountException;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;

// =============================================================================
// UPLOADS SÃO DA CONTA — numa aplicação limpa (Testbench), sem nada do
// starter. Uma pessoa em DUAS contas (a pessoal e a de uma empresa) não lista,
// não baixa e não assina o upload da outra conta — nem pela web (a conta
// selecionada na sessão) nem pela API (a conta da chave). Sem conta atual, a
// consulta e o envio dão erro em vez de devolver tudo.
//
// O pacote não tem rota de listagem/download: as rotas abaixo são o que uma
// aplicação escreveria (Upload::query() e Upload::url(), sem filtro manual) —
// quem isola é o escopo da conta.
// =============================================================================

beforeEach(function (): void {
    $this->ana = $this->owner(['name' => 'Ana']);
    $this->bruno = $this->owner(['name' => 'Bruno']);

    $this->contaDaAna = app(AccountService::class)->personalAccountOf($this->ana);
    $this->contaDoBruno = app(AccountService::class)->personalAccountOf($this->bruno);

    // A empresa é do Bruno; a Ana entra como member.
    $this->empresa = app(AccountService::class)->createAccount('Empresa', $this->bruno);
    app(AccountService::class)->addMember($this->empresa, $this->ana, AccountRole::Member);

    $envia = fn (Account $conta, User $quem, string $nome) => Accounts::actingAs(
        $conta,
        fn () => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), $nome)),
        $quem,
    );

    $this->daAna = $envia($this->contaDaAna, $this->ana, 'da-ana.pdf');
    $this->daEmpresa = $envia($this->empresa, $this->ana, 'da-empresa.pdf');
    $this->doBruno = $envia($this->contaDoBruno, $this->bruno, 'do-bruno.pdf');

    // O que uma aplicação escreveria: listar e assinar pelo model, sem filtro.
    $rotas = function (): void {
        Route::get('teste/uploads', fn () => response()->json(Upload::query()->orderBy('id')->pluck('codigo_publico')));
        Route::get('teste/uploads/{uuid}/url', fn (string $uuid) => response()->json([
            'url' => Upload::query()->where('uuid', $uuid)->firstOrFail()->url(),
        ]));
    };

    Route::middleware(['web', 'auth'])->group($rotas);
    Route::middleware(['api', 'resolve.tenant'])->prefix('api/v1')->group($rotas);
});

/**
 * @return array<string, string>
 */
function chaveDaConta(Account $conta, User $criador): array
{
    ['api_key' => $key, 'secret_key' => $secret] = Accounts::actingAs(
        $conta,
        fn (): array => app(ApiKeyService::class)->create($criador, ['name' => 'Integração']),
        $criador,
    );

    return ['X-Api-Key' => (string) $key->public_key, 'Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'];
}

it('WEB: a pessoa em duas contas só lista e assina o upload da conta selecionada', function (): void {
    // Sem seleção: a conta pessoal.
    $this->actingAs($this->ana)->getJson('/teste/uploads')
        ->assertOk()
        ->assertExactJson([$this->daAna->codigo_publico]);

    $this->getJson("/teste/uploads/{$this->daEmpresa->uuid}/url")->assertNotFound();
    $this->getJson("/teste/uploads/{$this->doBruno->uuid}/url")->assertNotFound();

    $propria = (string) $this->getJson("/teste/uploads/{$this->daAna->uuid}/url")->assertOk()->json('url');
    expect($propria)->toContain('signature=');

    // A empresa selecionada: só o da empresa.
    $this->withSession([CurrentAccount::sessionKey() => (string) $this->empresa->uuid])
        ->getJson('/teste/uploads')
        ->assertOk()
        ->assertExactJson([$this->daEmpresa->codigo_publico]);

    $this->withSession([CurrentAccount::sessionKey() => (string) $this->empresa->uuid])
        ->getJson("/teste/uploads/{$this->daAna->uuid}/url")
        ->assertNotFound();
});

it('API: a chave de cada conta só lista e assina o upload da própria conta', function (): void {
    $daEmpresa = chaveDaConta($this->empresa, $this->bruno);
    $daAna = chaveDaConta($this->contaDaAna, $this->ana);

    $this->getJson('/api/v1/teste/uploads', $daEmpresa)->assertOk()->assertExactJson([$this->daEmpresa->codigo_publico]);
    $this->getJson("/api/v1/teste/uploads/{$this->daAna->uuid}/url", $daEmpresa)->assertNotFound();
    $this->getJson("/api/v1/teste/uploads/{$this->doBruno->uuid}/url", $daEmpresa)->assertNotFound();
    $this->getJson("/api/v1/teste/uploads/{$this->daEmpresa->uuid}/url", $daEmpresa)->assertOk();

    $this->getJson('/api/v1/teste/uploads', $daAna)->assertOk()->assertExactJson([$this->daAna->codigo_publico]);
    $this->getJson("/api/v1/teste/uploads/{$this->daEmpresa->uuid}/url", $daAna)->assertNotFound();
});

it('a URL assinada só sai para upload da conta atual (ou em modo sistema declarado)', function (): void {
    // O registro chegou à mão por fora do escopo (modo sistema): na conta da
    // Ana, pedir a URL dele é recusado.
    $daEmpresa = Accounts::asSystem('teste: registro na mão', fn () => Upload::query()->whereKey($this->daEmpresa->id)->sole());

    expect(fn () => Accounts::actingAs($this->contaDaAna, fn () => $daEmpresa->url(), $this->ana))
        ->toThrow(UploadOutsideAccountException::class)
        // Sem conta nenhuma: recusa também.
        ->and(fn () => $daEmpresa->url())->toThrow(UploadOutsideAccountException::class)
        // Na conta dele, sai.
        ->and(Accounts::actingAs($this->empresa, fn () => $daEmpresa->url(), $this->bruno))->toContain('signature=')
        // Em modo sistema declarado (o /admin), sai.
        ->and(Accounts::asSystem('teste: /admin', fn () => $daEmpresa->url()))->toContain('signature=');
});

it('a assinatura de um upload da conta não abre o arquivo de outra conta', function (): void {
    $url = Accounts::actingAs($this->contaDaAna, fn () => $this->daAna->url(), $this->ana);
    $trocada = (string) preg_replace('#/storage/[^?]+#', '/storage/'.$this->daEmpresa->path, $url);

    expect($trocada)->not->toBe($url);

    $this->get($trocada)->assertForbidden();
    $this->get($url)->assertOk();
});

it('sem conta atual: a consulta e o envio dão erro — nunca a lista de todas as contas', function (): void {
    expect(fn () => Upload::query()->count())->toThrow(MissingAccountContextException::class)
        ->and(fn () => Upload::query()->pluck('codigo_publico'))->toThrow(MissingAccountContextException::class)
        ->and(fn () => app(SecureUploadService::class)->handle(fixtureArquivoEnviado(fixtureBytesPdf(), 'sem-conta.pdf')))
        ->toThrow(MissingAccountContextException::class);

    // O envio recusado não deixou arquivo no disco: só os três do arranjo.
    expect(Storage::disk('local')->allFiles('uploads'))->toHaveCount(3);
});

it('a conta de um upload não muda, e gravar em outra conta é recusado', function (): void {
    expect(fn () => Accounts::actingAs($this->contaDaAna, fn () => Upload::query()->whereKey($this->daAna->id)->sole()->forceFill(['account_id' => $this->empresa->id])->save(), $this->ana))
        ->toThrow(CrossAccountWriteException::class);

    expect(Accounts::asSystem('teste: conta', fn () => (int) Upload::query()->whereKey($this->daAna->id)->value('account_id')))->toBe((int) $this->contaDaAna->id);
});
