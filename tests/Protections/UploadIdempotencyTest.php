<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// IDEMPOTENCY-KEY NO POST /api/v1/uploads, numa aplicação LIMPA.
//
// O reenvio do MESMO arquivo com a mesma chave não grava de novo (nem no banco,
// nem no armazenamento); arquivo diferente com a mesma chave é recusado. A
// URL assinada da resposta é credencial enquanto vale: não vai para a tabela
// (nem cifrada) e não volta na repetição — que devolve "já processada" com os
// identificadores, o tipo, o tamanho, o hash e a situação.
// =============================================================================

it('o mesmo arquivo com a mesma chave: um upload, um arquivo no disco, e o replay sem a URL assinada', function (): void {
    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);
    $headers = [...$this->credentials($key, $secret), 'Idempotency-Key' => 'upload-2026-0001-abcdefgh'];
    $disk = Storage::disk((string) config('uploads.disk'));
    $before = count($disk->allFiles());

    $first = $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'nota.pdf')], $headers);
    $replay = $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'nota.pdf')], $headers);

    $first->assertCreated();
    $url = (string) $first->json('data.url');
    expect($url)->toContain('signature=');

    $replay->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.uuid', $first->json('data.uuid'))
        ->assertJsonPath('data.codigo_publico', $first->json('data.codigo_publico'))
        ->assertJsonPath('data.mime', 'application/pdf')
        ->assertJsonPath('data.size', $first->json('data.size'))
        ->assertJsonPath('data.sha256', $first->json('data.sha256'))
        ->assertJsonPath('data.status', $first->json('data.status'))
        ->assertJsonPath('idempotency.body_withheld', true)
        ->assertJsonMissingPath('data.url')
        ->assertJsonMissingPath('data.path')
        ->assertJsonMissingPath('data.original_name');

    $row = DB::table(IdempotencyStore::TABLE)->sole();
    $decrypted = Crypt::decryptString((string) $row->response);

    expect($this->inAccountOf($owner, fn (): int => Upload::query()->count()))->toBe(1)
        ->and(count($disk->allFiles()))->toBe($before + 1)
        ->and($replay->getContent())->not->toContain('signature=')
        ->and((bool) $row->response_withheld)->toBeTrue()
        ->and($decrypted)->not->toContain('signature')
        ->and($decrypted)->not->toContain('nota.pdf')
        ->and(json_encode($row))->not->toContain('signature');
});

it('arquivo DIFERENTE com a mesma chave: 422 idempotency_key_reused, nada gravado', function (): void {
    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);
    $headers = [...$this->credentials($key, $secret), 'Idempotency-Key' => 'upload-2026-0002-abcdefgh'];
    $disk = Storage::disk((string) config('uploads.disk'));

    $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPdf(), 'nota.pdf')], $headers)->assertCreated();
    $after = count($disk->allFiles());

    // Mesmo nome, conteúdo diferente: o hash do arquivo entra na forma canônica.
    $this->post('/api/v1/uploads', ['file' => fixtureArquivoEnviado(fixtureBytesPngDe(2, 2), 'nota.pdf')], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect($this->inAccountOf($owner, fn (): int => Upload::query()->count()))->toBe(1)
        ->and(count($disk->allFiles()))->toBe($after);
});
