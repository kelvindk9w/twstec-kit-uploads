<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Confidential\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use Twstec\Kit\Uploads\Confidential\ConfidentialAccess;
use Twstec\Kit\Uploads\Confidential\ConfidentialStorage;
use Twstec\Kit\Uploads\Confidential\Exceptions\ConfidentialStorageUnavailableException;
use Twstec\Kit\Uploads\Confidential\Exceptions\UndecryptableUploadException;
use Twstec\Kit\Uploads\Confidential\Keyring;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * GET da URL de um upload CONFIDENCIAL (rota `uploads.confidential`,
 * registrada pelo pacote): confere a URL e a regra (ConfidentialAccess),
 * grava o rastro e DECIFRA EM FLUXO — o arquivo inteiro nunca fica em
 * memória nem em claro no disco.
 *
 * Sem sessão nem cookie (a URL é a credencial, curta e amarrada a quem a
 * gerou): serve igual ao painel, à API e ao /admin. Limitada por IP
 * (`uploads.confidential.rate_limit` por minuto).
 *
 * Resposta: o tipo real do arquivo, o tamanho exato (Content-Length — corte
 * no meio fica visível ao cliente), `inline` para visualizar ou `attachment`
 * para baixar (com o nome original saneado), `nosniff` e `no-store`.
 */
final class ConfidentialDownloadController
{
    public function __invoke(Request $request, string $upload, ConfidentialAccess $access, ConfidentialStorage $storage): StreamedResponse
    {
        ['upload' => $registro, 'actor' => $actor, 'context' => $context, 'download' => $download] = $access->authorizeDownload($request, $upload);

        if (! Keyring::fromConfig()->available()) {
            $access->recordFailedDelivery($registro, $actor, $context, $download, __('uploads.confidential.unavailable'));

            throw ConfidentialStorageUnavailableException::because(Keyring::fromConfig()->status());
        }

        // Rotação em andamento: o registro pode ter mudado de caminho entre a
        // leitura e agora — relê uma vez antes de desistir.
        if (! $storage->exists($registro)) {
            $registro = $access->refresh($registro);

            if ($registro === null || ! $storage->exists($registro)) {
                if ($registro !== null) {
                    $access->recordFailedDelivery($registro, $actor, $context, $download, __('uploads.confidential.file_missing'));
                }

                abort(404);
            }
        }

        $access->recordDelivery($registro, $actor, $context, $download);

        $headers = [
            'Content-Type' => (string) $registro->mime,
            'Content-Length' => (string) $registro->size,
            'Content-Disposition' => self::disposition($registro, $download),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ];

        return new StreamedResponse(static function () use ($storage, $registro): void {
            try {
                $storage->stream($registro, static function (string $pedaco): void {
                    echo $pedaco;
                    flush();
                });
            } catch (UndecryptableUploadException $exception) {
                // Nada que não autentica saiu; o resto não sai. O cliente vê o
                // tamanho incompleto.
                Log::error('upload.confidential_undecryptable', ['upload_uuid' => $registro->uuid, 'reason' => $exception->reason]);
            } catch (Throwable $exception) {
                Log::error('upload.confidential_stream_failed', ['upload_uuid' => $registro->uuid, 'exception' => $exception::class]);
            }
        }, 200, $headers);
    }

    private static function disposition(Upload $upload, bool $download): string
    {
        $nome = (string) $upload->original_name;
        $ascii = Str::ascii($nome);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $ascii);
        $ascii = $ascii === '' ? 'arquivo' : $ascii;
        $nome = str_replace(['/', '\\', '%'], '_', $nome);

        return HeaderUtils::makeDisposition(
            $download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $nome === '' ? $ascii : $nome,
            $ascii,
        );
    }
}
