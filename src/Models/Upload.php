<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;
use Twstec\Kit\Accounts\Account\Concerns\BelongsToAccount;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;
use Twstec\Kit\Uploads\Access\UploadOutsideAccountException;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Confidential\ConfidentialAccess;
use Twstec\Kit\Uploads\Enums\UploadStatus;
use Twstec\Kit\Uploads\Retention\LegalHold;

/**
 * Registro de upload (só arquivo aprovado pela validação de segurança).
 *
 * Só existe registro para arquivo que PASSOU pela validação de segurança
 * (rejeitados não tocam o banco nem o disco — só o log). O `path` é sempre
 * uuid + extensão derivada do MIME real; o nome original é guardado
 * sanitizado, apenas para exibição.
 *
 * DONO: a CONTA (`account_id`), como projetos e chaves de API — e quem
 * enviou fica em `created_by` (pode sair da conta; o upload continua dela).
 * Web e API gravam do mesmo jeito: a conta atual e quem está agindo. O
 * escopo da conta (BelongsToAccount) filtra toda consulta pela conta atual e
 * dá erro sem conta.
 *
 * Duas exceções, ambas SEM conta (`account_id` nulo) e fora do alcance de
 * qualquer consulta de conta:
 * - FOTO PESSOAL (`personal` = true): a foto de perfil é da PESSOA, não de
 *   uma conta — aparece em todas as contas dela. Só nasce em modo sistema
 *   declarado (SecureUploadService::handlePersonal) e só é lida pela foto de
 *   perfil (Concerns\HasAvatar), restrita à foto da própria pessoa.
 * - ÓRFÃO da migração (`orphaned_at` preenchido): registro antigo cujo dono
 *   não existe mais; sai no `uploads:prune-orphans`.
 *
 * - DESVINCULADO sob guarda legal (`detached_at` preenchido): o dono (a
 *   conta, a pessoa) foi excluído, mas o arquivo está sob guarda legal
 *   (`retain_until` no futuro) — fica sem conta e sem autor até o prazo
 *   vencer, quando o `uploads:erase-expired-holds` o APAGA.
 *
 * CLASSIFICAÇÃO (`classification`, Classification\UploadClassification): `private`
 * por padrão; `confidential` é cifrado no armazenamento (a versão da chave
 * em `encryption_key_id`) e só é entregue pela rota da aplicação, com trilha.
 *
 * GUARDA LEGAL (`retain_until`, `retention_reason`): enquanto vale, o upload
 * não é APAGADO — nem pela exclusão do dono (que o desvincula e registra a
 * recusa na trilha), nem pela limpeza, nem por `delete()` direto (recusado,
 * com a recusa na trilha). Ver Retention\LegalHold.
 *
 * Identificadores (3 camadas — anti-enumeração): `id` interno nunca exposto; `uuid`
 * externo; `codigo_publico` legível UPL-xxxxxx (UNIQUE no banco). O `uuid` do
 * confidencial é escolhido antes da gravação: ele amarra o arquivo cifrado
 * ao registro (ver Confidential\StreamCipher).
 */
#[Fillable(['uuid', 'created_by', 'personal', 'classification', 'encryption_key_id', 'disk', 'path', 'original_name', 'mime', 'size', 'sha256', 'status'])]
class Upload extends Model
{
    use BelongsToAccount, HasPublicCode, HasUuids, RoutesByUuid;

    /**
     * Prefixo do código público legível: UPL-xxxxxx.
     */
    protected const PUBLIC_CODE_PREFIX = 'UPL';

    /**
     * Default da instância nova (espelha o default da migration).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'stored',
        'personal' => false,
        'classification' => 'private',
    ];

    /**
     * Foto pessoal nunca carrega conta (nem a atual): ela é da pessoa.
     */
    protected static function booted(): void
    {
        static::creating(function (self $upload): void {
            if ($upload->isPersonal() && $upload->getAttribute('account_id') !== null) {
                throw new LogicException('Foto pessoal não pertence a uma conta: grave-a pelo SecureUploadService::handlePersonal().');
            }
        });

        // Sob guarda legal, não se apaga — nem por delete() direto. A recusa
        // vai para a trilha (Retention\LegalHold).
        static::deleting(function (self $upload): void {
            app(LegalHold::class)->refuseDirectDeletion($upload);
        });
    }

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
            'size' => 'integer',
            'personal' => 'boolean',
            'orphaned_at' => 'datetime',
            'retain_until' => 'datetime',
            'detached_at' => 'datetime',
            'status' => UploadStatus::class,
            'classification' => UploadClassification::class,
        ];
    }

    /**
     * A foto pessoal (sem conta) só nasce em modo sistema declarado — ver
     * BelongsToAccount. Qualquer outro upload sem conta é recusado.
     */
    public function allowsRecordWithoutAccount(): bool
    {
        return (bool) $this->getAttribute('personal');
    }

    public function isPersonal(): bool
    {
        return (bool) $this->getAttribute('personal');
    }

    public function isOrphaned(): bool
    {
        return $this->getAttribute('orphaned_at') !== null;
    }

    public function isConfidential(): bool
    {
        return $this->classification === UploadClassification::Confidential;
    }

    /**
     * Sob guarda legal AGORA (o "guardar até" ainda não chegou)?
     */
    public function isUnderLegalHold(): bool
    {
        $ate = $this->getAttribute('retain_until');

        return $ate !== null && now()->lessThan($ate);
    }

    /**
     * O dono foi excluído e o upload ficou só pela guarda legal?
     */
    public function isDetached(): bool
    {
        return $this->getAttribute('detached_at') !== null;
    }

    /**
     * URL de acesso ao arquivo. NUNCA bucket público: tenta primeiro a URL
     * temporária assinada (S3/R2 e local com serve); se o driver não suportar,
     * cai para a URL padrão do disco.
     *
     * Assinar é dar acesso a quem receber a URL: só sai para o upload da
     * CONTA ATUAL, ou em modo sistema declarado (o /admin, a foto de perfil).
     * Upload de outra conta ou foto pessoal fora desses caminhos → recusa.
     *
     * CONFIDENCIAL: o armazenamento só tem o arquivo cifrado, então a URL é a
     * da rota da aplicação que decifra (Confidential\ConfidentialAccess) —
     * amarrada a quem a gerou e à conta, de validade curta
     * (`uploads.confidential.url_minutes`), e a geração vai para a trilha
     * (inclusive a recusa). `$download` = baixar (anexo) em vez de visualizar.
     *
     * @throws UploadOutsideAccountException
     */
    public function url(?DateTimeInterface $expiration = null, bool $download = false): string
    {
        if ($this->isConfidential()) {
            return app(ConfidentialAccess::class)->issueUrl($this, $expiration, $download);
        }

        $this->ensureSignable();

        $disk = Storage::disk((string) $this->disk);

        $expiration ??= now()->addMinutes((int) config('uploads.temporary_url_minutes', 15));

        try {
            return $disk->temporaryUrl((string) $this->path, $expiration);
        } catch (Throwable) {
            return $disk->url((string) $this->path);
        }
    }

    /**
     * Pode sair URL deste upload neste contexto? (a conta atual, ou modo
     * sistema declarado).
     */
    public function signableHere(): bool
    {
        if (Accounts::inSystemMode()) {
            return true;
        }

        $atual = Accounts::current();

        return ! $this->isPersonal() && $atual !== null && (string) $this->getAttribute('account_id') === (string) $atual->getKey();
    }

    private function ensureSignable(): void
    {
        if (! $this->signableHere()) {
            throw UploadOutsideAccountException::forSigning();
        }
    }
}
