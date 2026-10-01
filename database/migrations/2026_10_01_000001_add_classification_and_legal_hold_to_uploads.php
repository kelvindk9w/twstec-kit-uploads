<?php

declare(strict_types=1);

use Closure as SchemaChanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Uploads CONFIDENCIAIS e RETENÇÃO LEGAL (twstec/kit-uploads, 2.0.0-beta.10).
//
// Colunas novas em `uploads`:
// - `classification` (public | private | confidential), padrão `private` —
//   todo upload que já existe fica `private`, que é exatamente como ele já
//   era tratado (URL assinada de curta duração). Nada muda para ele;
// - `encryption_key_id`: a versão da chave que cifrou o arquivo (só nos
//   confidenciais; o mesmo id vai no cabeçalho do arquivo);
// - `retain_until` + `retention_reason`: a GUARDA LEGAL ("guardar até") e o
//   motivo;
// - `detached_at`: o dono (a conta, a pessoa) foi excluído e o arquivo ficou
//   só por causa da guarda — sem conta, sem autor, até o prazo vencer.
//
// E a chave estrangeira da conta passa de CASCADE para SET NULL: a exclusão
// da conta continua apagando os uploads dela (o pacote apaga explicitamente,
// antes — Erasure\UploadEraser), mas o banco não leva junto o que está sob
// guarda legal. No PostgreSQL a conta da pessoa excluída sai na mesma
// sentença (gatilho); sem esta troca, o arquivo guardado perderia o registro.
//
// Reversível: o `down` volta a chave para CASCADE e tira as colunas — e
// RECUSA (nada muda) enquanto houver upload confidencial (o arquivo dele está
// cifrado: sem as colunas, seria entregue cifrado como se fosse comum) ou
// desvinculado sob guarda (sem a coluna, viraria órfão e sairia na limpeza).
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        $this->preservingAvatars(function (): void {
            Schema::table('uploads', function (Blueprint $table): void {
                if (! Schema::hasColumn('uploads', 'classification')) {
                    $table->string('classification', 16)->default('private');
                }

                if (! Schema::hasColumn('uploads', 'encryption_key_id')) {
                    $table->string('encryption_key_id', 32)->nullable();
                }

                if (! Schema::hasColumn('uploads', 'retain_until')) {
                    $table->timestampTz('retain_until')->nullable();
                }

                if (! Schema::hasColumn('uploads', 'retention_reason')) {
                    $table->string('retention_reason', 160)->nullable();
                }

                if (! Schema::hasColumn('uploads', 'detached_at')) {
                    $table->timestampTz('detached_at')->nullable();
                }
            });

            Schema::table('uploads', function (Blueprint $table): void {
                if (! Schema::hasIndex('uploads', ['classification', 'encryption_key_id'])) {
                    $table->index(['classification', 'encryption_key_id']);
                }

                if (! Schema::hasIndex('uploads', ['detached_at', 'retain_until'])) {
                    $table->index(['detached_at', 'retain_until']);
                }
            });

            $this->accountForeignKey(nullOnDelete: true);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('uploads', 'classification') && DB::table('uploads')->where('classification', 'confidential')->exists()) {
            throw new RuntimeException('Há uploads confidenciais (cifrados): o rollback desta migration os entregaria cifrados como arquivos comuns. Nada foi alterado.');
        }

        if (Schema::hasColumn('uploads', 'detached_at') && DB::table('uploads')->whereNotNull('detached_at')->exists()) {
            throw new RuntimeException('Há uploads desvinculados sob guarda legal: o rollback desta migration os deixaria como órfãos, apagados na limpeza. Nada foi alterado.');
        }

        $this->preservingAvatars(function (): void {
            $this->accountForeignKey(nullOnDelete: false);

            Schema::table('uploads', function (Blueprint $table): void {
                if (Schema::hasIndex('uploads', ['classification', 'encryption_key_id'])) {
                    $table->dropIndex(['classification', 'encryption_key_id']);
                }

                if (Schema::hasIndex('uploads', ['detached_at', 'retain_until'])) {
                    $table->dropIndex(['detached_at', 'retain_until']);
                }
            });

            $colunas = array_values(array_filter(
                ['classification', 'encryption_key_id', 'retain_until', 'retention_reason', 'detached_at'],
                fn (string $coluna): bool => Schema::hasColumn('uploads', $coluna),
            ));

            if ($colunas !== []) {
                Schema::table('uploads', function (Blueprint $table) use ($colunas): void {
                    $table->dropColumn($colunas);
                });
            }
        });
    }

    /**
     * A chave estrangeira de `account_id`: SET NULL (esta versão) ou CASCADE
     * (a anterior). Sem a coluna (esquema anterior às contas), nada a fazer.
     */
    private function accountForeignKey(bool $nullOnDelete): void
    {
        if (! Schema::hasColumn('uploads', 'account_id') || ! Schema::hasTable('accounts')) {
            return;
        }

        $atual = $this->foreignKeyOnDelete('account_id');
        $desejado = $nullOnDelete ? 'set null' : 'cascade';

        if ($atual === $desejado) {
            return;
        }

        Schema::table('uploads', function (Blueprint $table) use ($atual, $nullOnDelete): void {
            if ($atual !== null) {
                $table->dropForeign(['account_id']);
            }

            $chave = $table->foreign('account_id')->references('id')->on('accounts');
            $nullOnDelete ? $chave->nullOnDelete() : $chave->cascadeOnDelete();
        });
    }

    private function foreignKeyOnDelete(string $column): ?string
    {
        foreach (Schema::getForeignKeys('uploads') as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return strtolower((string) ($foreignKey['on_delete'] ?? ''));
            }
        }

        return null;
    }

    /**
     * No SQLite, trocar chave estrangeira RECRIA a tabela `uploads` — e apagar
     * a original zeraria `users.avatar_upload_id` (nullOnDelete): o vínculo é
     * guardado antes e devolvido depois (os ids não mudam na cópia). A mesma
     * proteção da migration que passou os uploads para as contas.
     */
    private function preservingAvatars(SchemaChanges $schemaChanges): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || ! Schema::hasTable('users') || ! Schema::hasColumn('users', 'avatar_upload_id')) {
            $schemaChanges();

            return;
        }

        $fotos = DB::table('users')->whereNotNull('avatar_upload_id')->pluck('avatar_upload_id', 'id')->all();

        $schemaChanges();

        foreach ($fotos as $userId => $uploadId) {
            DB::table('users')->where('id', $userId)->whereNull('avatar_upload_id')
                ->whereExists(fn ($upload) => $upload->select(DB::raw(1))->from('uploads')->where('uploads.id', $uploadId))
                ->update(['avatar_upload_id' => $uploadId]);
        }
    }
};
