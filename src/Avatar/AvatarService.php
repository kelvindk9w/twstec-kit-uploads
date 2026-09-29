<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Avatar;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Services\SecureUploadService;

/**
 * Troca a FOTO DE PERFIL de uma pessoa: o arquivo passa pela função global de
 * upload (SecureUploadService::handlePersonal — só imagem, reprocessada) e
 * vira um upload PESSOAL (sem conta; a foto é da pessoa, não de uma conta).
 *
 * Usado pelo perfil do painel e pela rota web do avatar. A foto anterior fica
 * sem vínculo e sai na limpeza (`uploads:prune-orphans`, depois do prazo de
 * `uploads.prune.personal_after_hours`) — não na hora, para não quebrar a
 * imagem que ainda está na tela de quem trocou.
 */
final class AvatarService
{
    public const DIRECTORY = 'avatars';

    public function __construct(private readonly SecureUploadService $uploads) {}

    /**
     * @param  AuthUser&Model  $person  Dona da foto.
     * @param  AuthUser|null  $uploader  Quem enviou (padrão: a própria pessoa).
     *
     * @throws UploadRejectedException
     */
    public function replace(AuthUser&Model $person, UploadedFile $file, ?AuthUser $uploader = null): Upload
    {
        $upload = $this->uploads->handlePersonal(
            $file,
            $uploader ?? $person,
            directory: self::DIRECTORY,
            allowedTypes: ['image'],
        );

        $person->forceFill(['avatar_upload_id' => $upload->getKey()])->save();
        $person->unsetRelation('avatar');

        return $upload;
    }

    /**
     * Tira a FOTO DE PERFIL: a pessoa volta às iniciais.
     *
     * Só desfaz o vínculo — como na troca, a foto que saiu fica sem uso e é
     * apagada (banco e disco) pela limpeza (`uploads:prune-orphans`, depois
     * do prazo de `uploads.prune.personal_after_hours`), não na hora: a
     * imagem pode ainda estar na tela de quem removeu. Sem foto, nada muda.
     *
     * @param  AuthUser&Model  $person  Dona da foto.
     */
    public function remove(AuthUser&Model $person): void
    {
        if ($person->getAttribute('avatar_upload_id') === null) {
            return;
        }

        $person->forceFill(['avatar_upload_id' => null])->save();
        $person->unsetRelation('avatar');
    }
}
