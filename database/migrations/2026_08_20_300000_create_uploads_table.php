<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Uploads Seguros.
//
// uploads: registro de TODO arquivo aceito pela função global de upload.
// Arquivo rejeitado NUNCA vira registro (só log). O `path` é uuid + extensão
// derivada do MIME real — o nome original NUNCA compõe o caminho (guardado
// sanitizado em `original_name`, apenas para exibição).
//
// Vínculo: `tenant_uuid` quando o upload veio da API (ResolveTenant — o uuid
// do dono da chave, mesma convenção do request log); `user_id` quando veio
// da web autenticada.
//
// Identificadores (3 camadas, anti-enumeração): `id` interno nunca exposto; `uuid`
// externo; `codigo_publico` legível UPL-xxxxxx (UNIQUE — a unicidade é do banco).
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('codigo_publico', 32)->unique();
            // Tenant da API (uuid do dono da chave — ResolveTenant). Null na web.
            $table->uuid('tenant_uuid')->nullable();
            // Dono na web autenticada. Null na API (o vínculo é o tenant_uuid).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            // Nome original sanitizado — SOMENTE para exibição (nunca path).
            $table->string('original_name');
            // MIME REAL detectado por magic bytes (nunca o declarado).
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            // Integridade do conteúdo FINAL persistido (pós re-encode).
            $table->char('sha256', 64);
            $table->string('status', 20)->default('stored');
            $table->timestampsTz();

            $table->index(['tenant_uuid', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
