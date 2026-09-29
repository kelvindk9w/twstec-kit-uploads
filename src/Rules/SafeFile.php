<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Services\FileSecurityValidator;

/**
 * A validação de SEGURANÇA de arquivo (conteúdo real, não extensão) como regra de validação,
 * para os formulários que não são Form Request — hoje, os do Filament.
 *
 * Por que existe: o SecureUploadService recusa o arquivo malicioso de
 * qualquer forma, mas ele só roda na hora de PERSISTIR. Sem esta regra, um
 * .txt renomeado para .png só falharia depois do "Salvar", como erro de
 * servidor, em vez de aparecer embaixo do campo como qualquer outro erro de
 * formulário. Mesma lei, avisada na hora certa.
 *
 * A regra NÃO substitui o service: quem persiste continua sendo ele, e ele
 * revalida (é a função global única de upload — os dois pontos de controle
 * lendo a MESMA FileSecurityValidator).
 */
final class SafeFile implements ValidationRule
{
    /**
     * @param  list<string>  $allowedTypes  Chaves de config('uploads.types') — ex.: ['image'].
     */
    public function __construct(private readonly array $allowedTypes = ['image']) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $caminho = $value->getRealPath();

        if ($caminho === false || ! is_file($caminho)) {
            return;
        }

        $conteudo = file_get_contents($caminho);

        if ($conteudo === false) {
            $fail(__('uploads.rejected.unreadable'));

            return;
        }

        try {
            app(FileSecurityValidator::class)->validate(
                $conteudo,
                strtolower($value->getClientOriginalExtension()),
                $this->allowedTypes,
            );
        } catch (UploadRejectedException $exception) {
            $fail($exception->getMessage());
        }
    }
}
