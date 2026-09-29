<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Tests\Fixtures;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Models\Concerns\KitAuthenticatable;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;
use Twstec\Kit\Uploads\Concerns\HasAvatar;

/**
 * O model de usuário MÍNIMO de uma aplicação que instala o pacote: o do
 * esqueleto Laravel + a trait e o contrato do twstec/kit-auth + a foto de
 * perfil deste pacote (HasAvatar). Nada do starter.
 */
class User extends Authenticatable implements AuthUser, HasLocalePreference
{
    use HasAvatar, HasPublicCode, HasUuids, KitAuthenticatable, Notifiable, RoutesByUuid;

    protected const PUBLIC_CODE_PREFIX = 'USR';

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'locale'];

    protected $hidden = ['password', 'transaction_password', 'remember_token'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Conta de teste gravada no banco. Campos fora do $fillable (status,
     * e-mail confirmado, segundo fator…) entram por forceFill, como a
     * aplicação faria.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fixture(array $attributes = []): self
    {
        $fillable = ['name', 'email', 'password', 'locale'];

        $user = self::createWithPublicCodeRetry([
            'name' => 'Pessoa de Teste',
            'email' => 'pessoa'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'senha-correta',
            ...array_intersect_key($attributes, array_flip($fillable)),
        ]);

        $extra = array_diff_key($attributes, array_flip($fillable));

        if ($extra !== []) {
            $user->forceFill($extra)->save();
        }

        return $user;
    }
}
