<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Services;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Uploads\Enums\UploadStatus;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Models\Upload;

/**
 * FUNÇÃO GLOBAL ÚNICA de upload: toda parte do sistema usa ela.
 *
 * Pipeline:
 *   (a) validação de formulário — DELEGADA ao chamador via Form Request
 *       (arquivo presente, tamanho grosseiro, mimes de formulário);
 *   (b) validação de SEGURANÇA do arquivo (FileSecurityValidator — magic
 *       bytes, allowlist, extensão divergente, scripts/polyglot, PDF com
 *       JavaScript, re-encode de imagem);
 *   (c) nome seguro: uuid + extensão derivada do MIME REAL (o nome original
 *       NUNCA compõe o path);
 *   (d) persistência no disco (Cloudflare R2 em produção — S3-compatível);
 *   (e) registro em banco (a CONTA atual e quem enviou — igual na web e na
 *       API —, sha256 do conteúdo final) + log estruturado.
 *
 * DONO: handle() grava na conta atual (a da sessão na web, a da chave na
 * API) com `created_by` = quem está agindo — quem decide é o escopo da conta
 * (BelongsToAccount), não este serviço; sem conta atual, dá erro.
 * handlePersonal() grava a FOTO PESSOAL (da pessoa, sem conta), em modo
 * sistema declarado — só a foto de perfil usa.
 *
 * Arquivo REJEITADO não toca o disco nem o banco — só o log (sem dados
 * sensíveis; nome original sanitizado, LGPD).
 */
final class SecureUploadService
{
    public function __construct(
        private readonly FileSecurityValidator $validator,
    ) {}

    /**
     * Valida e sobe o arquivo, retornando o registro padronizado.
     *
     * @param  UploadedFile  $file  Arquivo já validado em nível de formulário.
     * @param  string|null  $disk  Disco Flysystem de destino (default: config uploads.disk).
     * @param  string|null  $directory  Diretório dentro do disco (default: config uploads.directory).
     * @param  list<string>|null  $allowedTypes  Tipos permitidos (chaves de
     *                                           config uploads.types — ex.: ['image'] no avatar). Default: config.
     *
     * @throws UploadRejectedException Arquivo reprovado na segurança (422).
     * @throws MissingAccountContextException Sem conta atual.
     * @throws RuntimeException Falha de infraestrutura ao persistir (500).
     */
    public function handle(
        UploadedFile $file,
        ?string $disk = null,
        ?string $directory = null,
        ?array $allowedTypes = null,
    ): Upload {
        // Sem conta atual (inclusive em modo sistema, que não é conta de
        // ninguém), nem chega a validar e gravar no disco.
        if (Accounts::current() === null) {
            throw MissingAccountContextException::forModel(Upload::class);
        }

        return $this->store($file, $disk, $directory, $allowedTypes, fn (array $attributes): Upload => Upload::createWithPublicCodeRetry($attributes));
    }

    /**
     * A FOTO PESSOAL: mesma validação, gravada SEM conta (é da pessoa e
     * aparece em todas as contas dela), com `created_by` = quem enviou (a
     * própria pessoa no perfil; o operador no /admin). Modo sistema num
     * ponto só (`uploads:personal-upload`): fora dele o escopo da conta
     * gravaria a conta atual.
     *
     * @param  list<string>|null  $allowedTypes
     *
     * @throws UploadRejectedException
     */
    public function handlePersonal(
        UploadedFile $file,
        AuthUser $uploader,
        ?string $disk = null,
        ?string $directory = null,
        ?array $allowedTypes = null,
    ): Upload {
        return $this->store($file, $disk, $directory, $allowedTypes, fn (array $attributes): Upload => Accounts::asSystem(
            'uploads:personal-upload',
            fn (): Upload => Upload::createWithPublicCodeRetry([
                ...$attributes,
                'personal' => true,
                'created_by' => $uploader->getKey(),
            ]),
        ));
    }

    /**
     * @param  list<string>|null  $allowedTypes
     * @param  Closure(array<string, mixed>): Upload  $create
     */
    private function store(
        UploadedFile $file,
        ?string $disk,
        ?string $directory,
        ?array $allowedTypes,
        Closure $create,
    ): Upload {
        $disk ??= (string) config('uploads.disk', 'local');
        $directory ??= (string) config('uploads.directory', 'uploads');

        /** @var list<string> $allowedTypes */
        $allowedTypes ??= array_values((array) config('uploads.allowed_types', ['image', 'pdf']));

        $originalName = $this->sanitizeOriginalName($file->getClientOriginalName());

        $content = file_get_contents($file->getRealPath() ?: '');

        if ($content === false) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }

        try {
            $result = $this->validator->validate(
                $content,
                strtolower($file->getClientOriginalExtension()),
                $allowedTypes,
            );

            $this->ensureSizeWithinLimit(strlen($result['content']), $result['mime'], $allowedTypes);
        } catch (UploadRejectedException $exception) {
            $this->logRejected($exception, $originalName, strlen($content), $disk, $directory);

            throw $exception;
        }

        // Nome seguro: uuid + extensão derivada do MIME REAL — o nome
        // original nunca toca o path.
        $path = trim($directory, '/').'/'.Str::uuid()->toString().'.'.$result['extension'];

        if (! Storage::disk($disk)->put($path, $result['content'])) {
            throw new RuntimeException('Falha ao persistir o arquivo no armazenamento.');
        }

        try {
            $upload = $create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => $originalName,
                'mime' => $result['mime'],
                'size' => strlen($result['content']),
                'sha256' => hash('sha256', $result['content']),
                'status' => UploadStatus::Stored,
            ]);
        } catch (Throwable $exception) {
            // Sem registro (sem conta atual, por exemplo), o arquivo não fica
            // no disco sem dono.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        Log::info('upload.stored', [
            'upload_uuid' => $upload->uuid,
            'codigo_publico' => $upload->codigo_publico,
            'disk' => $disk,
            'mime' => $result['mime'],
            'size' => $upload->size,
            'sha256' => $upload->sha256,
        ]);

        return $upload;
    }

    /**
     * Tamanho máximo POR TIPO (config) aplicado sobre o conteúdo FINAL
     * (pós re-encode). O Form Request do chamador já fez o corte grosseiro;
     * este é o limite de segurança definitivo.
     *
     * @param  list<string>  $allowedTypes
     */
    private function ensureSizeWithinLimit(int $bytes, string $mime, array $allowedTypes): void
    {
        /** @var array<string, array<string, mixed>> $types */
        $types = (array) config('uploads.types', []);

        foreach ($allowedTypes as $type) {
            $typeConfig = $types[$type] ?? [];

            if (! array_key_exists($mime, (array) ($typeConfig['mimes'] ?? []))) {
                continue;
            }

            $maxKb = (int) ($typeConfig['max_kb'] ?? 0);

            if ($maxKb > 0 && $bytes > $maxKb * 1024) {
                throw new UploadRejectedException('too_large', __('uploads.rejected.too_large', ['max' => $maxKb]));
            }

            return;
        }
    }

    /**
     * Log estruturado da rejeição — a trilha de segurança das tentativas
     * (arquivo malicioso não é persistido em lugar nenhum, mas a tentativa
     * fica registrada, com o nome original sanitizado).
     */
    private function logRejected(
        UploadRejectedException $exception,
        string $originalName,
        int $size,
        string $disk,
        string $directory,
    ): void {
        Log::warning('upload.rejected', [
            'reason' => $exception->reason,
            'original_name' => $originalName,
            'size' => $size,
            'disk' => $disk,
            'directory' => $directory,
        ]);
    }

    /**
     * Nome original SOMENTE para exibição: sem diretórios/traversal, sem
     * caracteres de controle, truncado. Nunca usado em path.
     */
    private function sanitizeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return Str::limit($name === '' ? 'arquivo' : $name, 255, '');
    }
}
