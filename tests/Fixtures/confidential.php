<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Storage;
use Twstec\Kit\Uploads\Confidential\ConfidentialStorage;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// Ajudas dos testes de uploads CONFIDENCIAIS. Nenhuma chave escrita no
// código: toda chave de teste nasce aleatória em tempo de execução.
// =============================================================================

/**
 * Uma chave de cifra nova, no formato da configuração (`base64:...`).
 */
function chaveConfidencialDeTeste(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/**
 * Liga os confidenciais com uma chave nova e a devolve.
 */
function ligarConfidenciais(?string $chave = null, array $anteriores = []): string
{
    $chave ??= chaveConfidencialDeTeste();

    config([
        'uploads.confidential.key' => $chave,
        'uploads.confidential.previous_keys' => $anteriores,
    ]);

    return $chave;
}

/**
 * PDF válido com uma MARCA de texto repetida até ~`$bytes` — o conteúdo
 * "secreto" que não pode aparecer no objeto cru. Só ASCII seguro (sem `<`,
 * `#`, `/JS`), para passar pela validação de conteúdo.
 */
function pdfConfidencial(string $marca = 'SEGREDO-DO-TITULAR-7Q4Z', int $bytes = 600): string
{
    $corpo = '';
    $linha = 0;

    while (strlen($corpo) < $bytes) {
        $corpo .= '% '.$marca.' linha '.($linha++).' '.bin2hex(random_bytes(8))."\n";
    }

    return "%PDF-1.4\n"
        .$corpo
        ."1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n"
        ."2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n"
        ."3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>\nendobj\n"
        ."trailer\n<</Root 1 0 R>>\n%%EOF";
}

/**
 * O objeto como está no armazenamento (sem decifrar).
 */
function objetoCru(Upload $upload): string
{
    return (string) Storage::disk((string) $upload->disk)->get((string) $upload->path);
}

/**
 * O conteúdo decifrado, pelo caminho do pacote.
 */
function conteudoDecifrado(Upload $upload): string
{
    $saida = '';

    app(ConfidentialStorage::class)->stream($upload, function (string $pedaco) use (&$saida): void {
        $saida .= $pedaco;
    });

    return $saida;
}

/**
 * Algum trecho de `$tamanho` bytes do original aparece no objeto cru?
 */
function objetoContemTrechoDo(string $cru, string $original, int $tamanho = 12): bool
{
    for ($i = 0; $i + $tamanho <= strlen($original); $i += 7) {
        if (str_contains($cru, substr($original, $i, $tamanho))) {
            return true;
        }
    }

    return false;
}
