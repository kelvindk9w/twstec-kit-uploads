<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Enums\UserStatus;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Access\UploadOutsideAccountException;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Confidential\ConfidentialAccess;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;
use Twstec\Kit\Uploads\Tests\Fixtures\User;

// =============================================================================
// UPLOADS CONFIDENCIAIS — TRILHA DE ACESSO e ISOLAMENTO (aplicação limpa):
//
// - gerar a URL, visualizar e baixar gravam linha em `audit_events` (ator,
//   conta, arquivo, contexto), antes de a URL existir / do primeiro byte;
// - a URL é da rota da aplicação (o armazenamento só tem o cifrado), assinada,
//   curta e amarrada a quem gerou e à conta;
// - de OUTRA conta: não acha, não assina (recusa na trilha) e a URL de quem
//   perdeu o acesso dá 404 (recusa na trilha);
// - URL adulterada ou vencida: 403 (recusa na trilha, sem confiar na URL);
// - sem chave na hora da entrega: 503 (recusa na trilha).
// =============================================================================

/**
 * @return array{ana: User, bruno: User, empresa: Account, upload: Upload, original: string}
 */
function cenarioConfidencial(): array
{
    $ana = User::fixture(['name' => 'Ana', 'email_verified_at' => now()]);
    $bruno = User::fixture(['name' => 'Bruno', 'email_verified_at' => now()]);
    $empresa = app(AccountService::class)->createAccount('Empresa da Ana', $ana);
    app(AccountService::class)->addMember($empresa, $bruno, AccountRole::Member);

    $original = pdfConfidencial('IDENTIDADE-DO-CLIENTE');

    $upload = Accounts::actingAs($empresa, fn (): Upload => app(SecureUploadService::class)->handle(
        fixtureArquivoEnviado($original, 'Documento de identidade.pdf'),
        classification: UploadClassification::Confidential,
    ), $ana);

    return compact('ana', 'bruno', 'empresa', 'upload', 'original');
}

function trilhaConfidencial(string $acao, AuditOutcome $resultado = AuditOutcome::Success): Collection
{
    return AuditEvent::query()->where('action', $acao)->where('outcome', $resultado)->orderBy('id')->get();
}

beforeEach(function (): void {
    ligarConfidenciais();
});

it('GERAR a URL grava a trilha (ator, conta, arquivo, contexto) e devolve a rota da aplicação — nunca o armazenamento', function (): void {
    $c = cenarioConfidencial();

    $url = Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url(), $c['bruno']);

    expect($url)->toContain('/uploads/confidential/'.$c['upload']->uuid)
        ->and($url)->toContain('signature=')
        ->and($url)->not->toContain((string) $c['upload']->path)
        ->and($url)->not->toContain('/storage/');

    $linha = trilhaConfidencial(ConfidentialAccess::ISSUED)->sole();

    expect($linha->actor_uuid)->toBe((string) $c['bruno']->uuid)
        ->and($linha->tenant_uuid)->toBe((string) $c['empresa']->uuid)
        ->and($linha->subject_type)->toBe('upload')
        ->and($linha->subject_uuid)->toBe((string) $c['upload']->uuid)
        ->and($linha->context->value)->toBe('panel')
        ->and($linha->changes['disposition']['after'])->toBe('inline');
});

it('VISUALIZAR entrega o conteúdo decifrado, com a trilha; BAIXAR entrega como anexo, com a trilha', function (): void {
    $c = cenarioConfidencial();

    $ver = Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url(), $c['bruno']);
    $baixar = Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url(download: true), $c['bruno']);

    $resposta = $this->get($ver);
    $resposta->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Length', (string) strlen($c['original']))
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($resposta->streamedContent())->toBe($c['original'])
        ->and((string) $resposta->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $resposta->headers->get('Content-Disposition'))->toStartWith('inline');

    $anexo = $this->get($baixar);
    $anexo->assertOk();

    expect($anexo->streamedContent())->toBe($c['original'])
        ->and((string) $anexo->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and((string) $anexo->headers->get('Content-Disposition'))->toContain('Documento de identidade.pdf');

    $visto = trilhaConfidencial(ConfidentialAccess::VIEWED)->sole();
    $baixado = trilhaConfidencial(ConfidentialAccess::DOWNLOADED)->sole();

    foreach ([$visto, $baixado] as $linha) {
        expect($linha->actor_uuid)->toBe((string) $c['bruno']->uuid)
            ->and($linha->tenant_uuid)->toBe((string) $c['empresa']->uuid)
            ->and($linha->subject_uuid)->toBe((string) $c['upload']->uuid)
            ->and($linha->context->value)->toBe('panel')
            ->and($linha->ip)->not->toBeNull();
    }
});

it('de OUTRA conta: não acha o upload, e o objeto que chegou à mão não assina — recusa na trilha', function (): void {
    $c = cenarioConfidencial();
    $deFora = User::fixture(['email_verified_at' => now()]);
    $contaDeFora = app(AccountService::class)->personalAccountOf($deFora);

    expect(Accounts::actingAs($contaDeFora, fn () => Upload::query()->where('uuid', $c['upload']->uuid)->first(), $deFora))->toBeNull();

    expect(fn () => Accounts::actingAs($contaDeFora, fn () => $c['upload']->url(), $deFora))
        ->toThrow(UploadOutsideAccountException::class);

    $recusa = trilhaConfidencial(ConfidentialAccess::ISSUED, AuditOutcome::Denied)->sole();

    expect($recusa->actor_uuid)->toBe((string) $deFora->uuid)
        ->and($recusa->tenant_uuid)->toBe((string) $contaDeFora->uuid)
        ->and($recusa->subject_uuid)->toBe((string) $c['upload']->uuid)
        ->and(trilhaConfidencial(ConfidentialAccess::ISSUED))->toHaveCount(0);
});

it('URL de quem PERDEU o acesso (saiu da conta, foi bloqueado) dá 404 — o mesmo de "não existe" — e a recusa vai para a trilha', function (string $perda): void {
    $c = cenarioConfidencial();

    $url = Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url(), $c['bruno']);

    match ($perda) {
        'saiu da conta' => app(AccountService::class)->removeMember($c['empresa'], $c['bruno']),
        'bloqueado' => $c['bruno']->forceFill(['status' => UserStatus::Blocked])->save(),
    };

    $this->get($url)->assertNotFound();

    $recusa = trilhaConfidencial(ConfidentialAccess::VIEWED, AuditOutcome::Denied)->sole();

    expect($recusa->subject_uuid)->toBe((string) $c['upload']->uuid)
        ->and($recusa->tenant_uuid)->toBe((string) $c['empresa']->uuid)
        ->and($recusa->reason)->not->toBeEmpty()
        ->and(trilhaConfidencial(ConfidentialAccess::VIEWED))->toHaveCount(0);
})->with(['saiu da conta', 'bloqueado']);

it('URL assinada para OUTRA conta não abre o upload desta: 404 com recusa na trilha', function (): void {
    $c = cenarioConfidencial();
    $contaDoBruno = app(AccountService::class)->personalAccountOf($c['bruno']);

    // Uma URL legítima da conta pessoal do Bruno, apontando para o upload da
    // empresa (o que um bug de tela poderia produzir): a conta não bate.
    $url = URL::temporarySignedRoute(ConfidentialAccess::ROUTE, now()->addMinutes(5), [
        'upload' => (string) $c['upload']->uuid,
        'acc' => (string) $contaDoBruno->uuid,
        'act' => (string) $c['bruno']->uuid,
        'ctx' => 'panel',
        'dl' => 0,
    ]);

    $this->get($url)->assertNotFound();

    expect(trilhaConfidencial(ConfidentialAccess::VIEWED, AuditOutcome::Denied))->toHaveCount(1)
        ->and(trilhaConfidencial(ConfidentialAccess::VIEWED))->toHaveCount(0);
});

it('URL ADULTERADA (outra conta, outro ator, outro arquivo) ou VENCIDA: 403, com a recusa na trilha sem confiar no que veio na URL', function (string $estrago): void {
    $c = cenarioConfidencial();
    $intruso = User::fixture(['email_verified_at' => now()]);

    $url = Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url(), $c['bruno']);

    $alvo = match ($estrago) {
        'ator' => str_replace('act='.$c['bruno']->uuid, 'act='.$intruso->uuid, $url),
        'download' => str_replace('dl=0', 'dl=1', $url),
        'vencida' => $url,
    };

    if ($estrago === 'vencida') {
        $this->travel(6)->minutes();
    }

    expect($alvo)->not->toBe($estrago === 'vencida' ? '' : $url);

    $this->get($alvo)->assertForbidden();

    $recusa = trilhaConfidencial(ConfidentialAccess::VIEWED, AuditOutcome::Denied)->sole();

    expect($recusa->actor_uuid)->toBeNull()
        ->and($recusa->tenant_uuid)->toBeNull()
        ->and($recusa->subject_uuid)->toBe((string) $c['upload']->uuid);
})->with(['ator', 'download', 'vencida']);

it('sem a chave na hora da entrega: 503 e a recusa na trilha — nada sai', function (): void {
    $c = cenarioConfidencial();
    $url = Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url(), $c['bruno']);

    config(['uploads.confidential.key' => null]);

    $resposta = $this->get($url);

    $resposta->assertStatus(503);

    expect($resposta->getContent())->not->toContain('IDENTIDADE-DO-CLIENTE')
        ->and(trilhaConfidencial(ConfidentialAccess::VIEWED, AuditOutcome::Denied))->toHaveCount(1)
        ->and(trilhaConfidencial(ConfidentialAccess::VIEWED))->toHaveCount(0);
});

it('sem ninguém agindo (um job, um comando), a URL do confidencial não sai — recusa na trilha', function (): void {
    $c = cenarioConfidencial();

    expect(fn () => Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url()))
        ->toThrow(HttpException::class);

    expect(trilhaConfidencial(ConfidentialAccess::ISSUED, AuditOutcome::Denied))->toHaveCount(1)
        ->and(trilhaConfidencial(ConfidentialAccess::ISSUED))->toHaveCount(0);
});

it('em MODO SISTEMA (o /admin), quem gera é o operador logado: a URL vale enquanto ele for admin', function (): void {
    // A flag de acesso ao painel é do aplicativo (a migration de usuários
    // dele); aqui, a de uma aplicação que tem o /admin.
    Schema::table('users', fn (Blueprint $tabela) => $tabela->boolean('is_admin')->default(false));

    $c = cenarioConfidencial();
    $operador = User::fixture(['email_verified_at' => now()]);
    $operador->forceFill(['is_admin' => true])->save();

    $this->actingAs($operador);

    $url = Accounts::asSystem('teste: /admin', fn (): string => $c['upload']->url());

    expect($url)->toContain('acc='.ConfidentialAccess::SYSTEM);

    auth()->logout();

    expect($this->get($url)->assertOk()->streamedContent())->toBe($c['original']);

    $emitida = trilhaConfidencial(ConfidentialAccess::ISSUED)->sole();

    expect($emitida->actor_uuid)->toBe((string) $operador->uuid)
        ->and($emitida->actor_is_admin)->toBeTrue()
        ->and($emitida->tenant_uuid)->toBe((string) $c['empresa']->uuid);

    // Deixou de ser admin: a URL que ele gerou não abre mais.
    $this->actingAs($operador);
    $outra = Accounts::asSystem('teste: /admin', fn (): string => $c['upload']->url());
    auth()->logout();
    $operador->forceFill(['is_admin' => false])->save();

    $this->get($outra)->assertNotFound();
});

it('pela API: a resposta do envio traz a URL do confidencial, e a trilha registra o contexto `api`', function (): void {
    config(['uploads.classification.default' => 'confidential']);

    $dono = $this->owner();

    $resposta = $this->postUpload($dono, fixtureArquivoEnviado(pdfConfidencial(), 'contrato.pdf'))->assertCreated();

    expect((string) $resposta->json('data.url'))->toContain('/uploads/confidential/');

    $linha = trilhaConfidencial(ConfidentialAccess::ISSUED)->sole();

    expect($linha->context->value)->toBe('api')
        ->and($linha->actor_uuid)->toBe((string) $dono->uuid);

    expect($this->get((string) $resposta->json('data.url'))->assertOk()->streamedContent())->toContain('SEGREDO-DO-TITULAR-7Q4Z');

    expect(trilhaConfidencial(ConfidentialAccess::VIEWED)->sole()->context->value)->toBe('api');
});

it('a entrega é limitada por IP (`uploads.confidential.rate_limit`)', function (): void {
    config(['uploads.confidential.rate_limit' => 2]);
    $c = cenarioConfidencial();
    $url = Accounts::actingAs($c['empresa'], fn (): string => $c['upload']->url(), $c['bruno']);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    $this->get($url)->assertStatus(429);
});
