<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

// =============================================================================
// Fixtures programáticas da suíte do pacote — geradas em bytes,
// sem arquivos binários no repositório. Cada gerador produz o MÍNIMO
// necessário para o finfo classificar o conteúdo (magic bytes reais).
// =============================================================================

/**
 * PDF mínimo válido (header %PDF- + estrutura básica + %%EOF).
 */
function fixtureBytesPdf(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n"
        ."2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n"
        ."3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>\nendobj\n"
        ."trailer\n<</Root 1 0 R>>\n%%EOF";
}

/**
 * PDF válido, porém com JavaScript embutido (/S/JavaScript + /JS) e ação
 * automática (/OpenAction) — política: REJEITADO.
 */
function fixtureBytesPdfComJavaScript(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj\n<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>\nendobj\n"
        ."2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n"
        ."3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>\nendobj\n"
        ."4 0 obj\n<</S/JavaScript/JS (app.alert('x'))>>\nendobj\n"
        ."trailer\n<</Root 1 0 R>>\n%%EOF";
}

/**
 * PNG real 1×1 (transparente), decodificável pela GD.
 */
function fixtureBytesPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        true,
    );
}

/**
 * Cabeçalho ELF falso (executável Linux) — magic bytes \x7fELF.
 */
function fixtureBytesElf(): string
{
    return "\x7fELF\x02\x01\x01\x00".random_bytes(64);
}

/**
 * Cria um UploadedFile de teste com CONTEÚDO REAL (a validação de segurança
 * lê os bytes do disco — UploadedFile::fake() não serve aqui).
 */
function fixtureArquivoEnviado(string $bytes, string $name): UploadedFile
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'upl_');
    file_put_contents($tmp, $bytes);

    return new UploadedFile($tmp, $name, null, null, true);
}

/**
 * PNG real de largura×altura gerado em runtime pela GD.
 */
function fixtureBytesPngDe(int $largura, int $altura): string
{
    $image = imagecreatetruecolor($largura, $altura);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}
