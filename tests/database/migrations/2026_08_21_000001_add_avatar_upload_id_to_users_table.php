<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A coluna da foto de perfil numa aplicação que usa a trait HasAvatar. É do
// APLICATIVO (no starter, a migration de usuários dele cria esta coluna); a
// suíte do pacote a cria aqui, depois da tabela `uploads` do pacote.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('avatar_upload_id')->nullable()->constrained('uploads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('avatar_upload_id');
        });
    }
};
