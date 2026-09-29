<?php

declare(strict_types=1);

use Closure as SchemaChanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Uploads passam a pertencer à CONTA (como projetos e chaves de API).
//
// Até aqui o dono era a pessoa, gravado de dois jeitos: pela web em `user_id`,
// pela API em `tenant_uuid` (o uuid da conta da chave — na conta pessoal, o da
// pessoa). Agora todo upload tem `account_id` (a conta) e `created_by` (quem
// enviou), e web e API gravam do mesmo jeito.
//
// Para cada registro antigo, nesta ordem:
//
// 1. FOTO DE PERFIL em uso (`users.avatar_upload_id` aponta para ele) enviada
//    pela web ou sem dono → vira FOTO PESSOAL (`personal`, sem conta — a foto
//    é da pessoa e aparece em todas as contas dela), `created_by` = `user_id`.
//    A foto que veio pela API de uma conta que existe segue a regra 3.
// 2. `user_id` → a CONTA PESSOAL dessa pessoa; `created_by` = `user_id`.
// 3. `tenant_uuid` → a conta com esse uuid (na 1.x e na F8a a conta pessoal
//    tem o uuid da pessoa, e a API grava o uuid da conta da chave);
//    `created_by` fica vazio — o registro antigo não guardava quem, por trás
//    da chave, enviou.
// 4. Nada disso (dono excluído, uuid que não aponta para conta nenhuma,
//    registro sem dono) → ÓRFÃO: `orphaned_at` = agora, sem conta. NUNCA é
//    atribuído a uma conta qualquer; o `uploads:prune-orphans` apaga o
//    registro e o arquivo depois do prazo (`uploads.prune.orphans_after_days`).
//
// Depois de conferir que nenhum registro ficou sem destino (senão, falha e —
// no PostgreSQL, que roda a migration numa transação — nada muda), saem
// `user_id` e `tenant_uuid`: nada mais os lê (a trilha de requisições tem o
// próprio `tenant_uuid`; o /admin mostra a conta).
//
// Os ARQUIVOS não mudam de lugar: `disk` e `path` continuam os mesmos, e a URL
// assinada de um arquivo antigo continua abrindo o mesmo conteúdo.
//
// Idempotente (cada passo recalcula a partir das colunas antigas e só mexe no
// que ainda não tem destino) e em lotes por faixa de id
// (`uploads.migration.chunk`, padrão 1000).
//
// Reversível: o `down` devolve `user_id`/`tenant_uuid` — foto pessoal e upload
// da conta pessoal de quem enviou voltam para `user_id` (o jeito da web);
// upload sem `created_by` ou de conta de empresa volta para `tenant_uuid` (o
// jeito da API). O órfão volta sem dono (o uuid antigo não apontava para
// ninguém e não é guardado).
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        $chunk = max(1, (int) config('uploads.migration.chunk', 1000));

        $this->preservingAvatars(function () use ($chunk): void {
            Schema::table('uploads', function (Blueprint $table): void {
                if (! Schema::hasColumn('uploads', 'account_id')) {
                    $table->unsignedBigInteger('account_id')->nullable();
                }

                if (! Schema::hasColumn('uploads', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }

                if (! Schema::hasColumn('uploads', 'personal')) {
                    $table->boolean('personal')->default(false);
                }

                if (! Schema::hasColumn('uploads', 'orphaned_at')) {
                    $table->timestampTz('orphaned_at')->nullable();
                }
            });

            if (Schema::hasColumn('uploads', 'user_id') || Schema::hasColumn('uploads', 'tenant_uuid')) {
                $this->assignOwners($chunk);

                $semDestino = DB::table('uploads')
                    ->whereNull('account_id')
                    ->where('personal', false)
                    ->whereNull('orphaned_at')
                    ->count();

                if ($semDestino > 0) {
                    throw new RuntimeException("Migração dos uploads: {$semDestino} registro(s) ficaram sem conta, sem foto pessoal e sem marca de órfão. Nada foi alterado.");
                }
            }

            $this->finishSchema();
        });
    }

    public function down(): void
    {
        $this->preservingAvatars(function (): void {
            Schema::table('uploads', function (Blueprint $table): void {
                if (! Schema::hasColumn('uploads', 'tenant_uuid')) {
                    $table->uuid('tenant_uuid')->nullable();
                }

                if (! Schema::hasColumn('uploads', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable();
                }
            });

            if (Schema::hasColumn('uploads', 'account_id')) {
                $this->restoreLegacyOwners();
            }

            Schema::table('uploads', function (Blueprint $table): void {
                if ($this->hasForeignKey('account_id')) {
                    $table->dropForeign(['account_id']);
                }

                if ($this->hasForeignKey('created_by')) {
                    $table->dropForeign(['created_by']);
                }

                if (Schema::hasIndex('uploads', ['account_id', 'status'])) {
                    $table->dropIndex(['account_id', 'status']);
                }

                if (Schema::hasIndex('uploads', ['personal', 'created_at'])) {
                    $table->dropIndex(['personal', 'created_at']);
                }

                if (Schema::hasIndex('uploads', ['orphaned_at'])) {
                    $table->dropIndex(['orphaned_at']);
                }
            });

            Schema::table('uploads', function (Blueprint $table): void {
                $table->dropColumn(array_values(array_filter(
                    ['account_id', 'created_by', 'personal', 'orphaned_at'],
                    fn (string $coluna): bool => Schema::hasColumn('uploads', $coluna),
                )));
            });

            Schema::table('uploads', function (Blueprint $table): void {
                if (! $this->hasForeignKey('user_id')) {
                    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                }

                if (! Schema::hasIndex('uploads', ['tenant_uuid', 'status'])) {
                    $table->index(['tenant_uuid', 'status']);
                }

                if (! Schema::hasIndex('uploads', ['user_id', 'status'])) {
                    $table->index(['user_id', 'status']);
                }
            });
        });
    }

    /**
     * Os quatro passos do cabeçalho, por faixa de id — um UPDATE por passo e
     * por faixa, nada carregado na memória.
     */
    private function assignOwners(int $chunk): void
    {
        $min = DB::table('uploads')->min('id');
        $max = DB::table('uploads')->max('id');

        if ($min === null) {
            return;
        }

        $temUserId = Schema::hasColumn('uploads', 'user_id');
        $temTenant = Schema::hasColumn('uploads', 'tenant_uuid');
        $temFoto = Schema::hasTable('users') && Schema::hasColumn('users', 'avatar_upload_id');
        $agora = now();

        for ($inicio = (int) $min; $inicio <= (int) $max; $inicio += $chunk) {
            $faixa = [$inicio, $inicio + $chunk - 1];

            // 1. Foto de perfil em uso: pessoal (a da API de uma conta que
            //    existe fica na conta — passo 3).
            if ($temFoto) {
                DB::table('uploads')
                    ->whereBetween('id', $faixa)
                    ->whereNull('account_id')
                    ->where('personal', false)
                    ->whereNull('orphaned_at')
                    ->whereIn('id', DB::table('users')->select('avatar_upload_id')->whereNotNull('avatar_upload_id'))
                    ->when($temTenant, fn ($query) => $query->where(fn ($q) => $q
                        ->when($temUserId, fn ($q) => $q->whereNotNull('user_id'))
                        ->orWhereNull('tenant_uuid')
                        ->orWhereNotExists(fn ($conta) => $conta->select(DB::raw(1))->from('accounts')->whereColumn('accounts.uuid', 'uploads.tenant_uuid'))))
                    ->update([
                        'personal' => true,
                        'created_by' => $temUserId ? DB::raw('user_id') : null,
                    ]);
            }

            // 2. Enviado pela web: a conta pessoal de quem enviou.
            if ($temUserId) {
                DB::table('uploads')
                    ->whereBetween('id', $faixa)
                    ->whereNull('account_id')
                    ->where('personal', false)
                    ->whereNull('orphaned_at')
                    ->whereNotNull('user_id')
                    ->update([
                        'account_id' => DB::raw('(select a.id from accounts a where a.personal_user_id = uploads.user_id)'),
                        'created_by' => DB::raw('user_id'),
                    ]);
            }

            // 3. Enviado pela API: a conta com aquele uuid.
            if ($temTenant) {
                DB::table('uploads')
                    ->whereBetween('id', $faixa)
                    ->whereNull('account_id')
                    ->where('personal', false)
                    ->whereNull('orphaned_at')
                    ->whereNotNull('tenant_uuid')
                    ->update([
                        'account_id' => DB::raw('(select a.id from accounts a where a.uuid = uploads.tenant_uuid)'),
                    ]);
            }

            // 4. Sem destino: órfão (nunca uma conta qualquer).
            DB::table('uploads')
                ->whereBetween('id', $faixa)
                ->whereNull('account_id')
                ->where('personal', false)
                ->whereNull('orphaned_at')
                ->update(['orphaned_at' => $agora]);
        }
    }

    /**
     * Chaves estrangeiras e índices das colunas novas; saem as antigas.
     */
    private function finishSchema(): void
    {
        Schema::table('uploads', function (Blueprint $table): void {
            if (! $this->hasForeignKey('account_id')) {
                // A conta sai com os uploads dela (os arquivos saem pelo
                // ouvinte da exclusão da conta — Support\UploadLifecycle).
                $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            }

            if (! $this->hasForeignKey('created_by')) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }

            if (! Schema::hasIndex('uploads', ['account_id', 'status'])) {
                $table->index(['account_id', 'status']);
            }

            if (! Schema::hasIndex('uploads', ['personal', 'created_at'])) {
                $table->index(['personal', 'created_at']);
            }

            if (! Schema::hasIndex('uploads', ['orphaned_at'])) {
                $table->index(['orphaned_at']);
            }
        });

        Schema::table('uploads', function (Blueprint $table): void {
            if ($this->hasForeignKey('user_id')) {
                $table->dropForeign(['user_id']);
            }

            if (Schema::hasIndex('uploads', ['user_id', 'status'])) {
                $table->dropIndex(['user_id', 'status']);
            }

            if (Schema::hasIndex('uploads', ['tenant_uuid', 'status'])) {
                $table->dropIndex(['tenant_uuid', 'status']);
            }
        });

        $antigas = array_values(array_filter(['user_id', 'tenant_uuid'], fn (string $coluna): bool => Schema::hasColumn('uploads', $coluna)));

        if ($antigas !== []) {
            Schema::table('uploads', function (Blueprint $table) use ($antigas): void {
                $table->dropColumn($antigas);
            });
        }
    }

    /**
     * O dono no jeito antigo, a partir da conta e de quem enviou.
     */
    private function restoreLegacyOwners(): void
    {
        // Foto pessoal e upload da CONTA PESSOAL de quem enviou: `user_id`
        // (o jeito da web).
        DB::table('uploads')
            ->whereNull('user_id')
            ->whereNotNull('created_by')
            ->where(fn ($query) => $query
                ->where('personal', true)
                ->orWhereExists(fn ($conta) => $conta->select(DB::raw(1))->from('accounts')
                    ->whereColumn('accounts.id', 'uploads.account_id')
                    ->whereColumn('accounts.personal_user_id', 'uploads.created_by')))
            ->update(['user_id' => DB::raw('created_by'), 'tenant_uuid' => null]);

        // O resto com conta: o uuid da conta (o jeito da API).
        DB::table('uploads')
            ->whereNull('user_id')
            ->whereNull('tenant_uuid')
            ->whereNotNull('account_id')
            ->update(['tenant_uuid' => DB::raw('(select a.uuid from accounts a where a.id = uploads.account_id)')]);
    }

    /**
     * No SQLite, pôr ou tirar chave estrangeira RECRIA a tabela `uploads`
     * (cópia, apaga a original, renomeia). Dentro da transação da migration o
     * SQLite ignora o desligamento das chaves estrangeiras, e apagar a
     * original zeraria `users.avatar_upload_id` (nullOnDelete) — a foto de
     * todo mundo sumiria. Aqui o vínculo é guardado antes e devolvido depois
     * (os ids dos uploads não mudam na cópia). No PostgreSQL as colunas mudam
     * no lugar e nada disso acontece.
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

    private function hasForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys('uploads') as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
