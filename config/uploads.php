<?php

declare(strict_types=1);

// =============================================================================
// Uploads Seguros — configuração padrão do pacote twstec/kit-uploads. A
// aplicação pode publicar a própria cópia (`vendor:publish
// --tag=uploads-config`); as chaves de primeiro nível dela prevalecem.
//
// Política (lei): o arquivo é validado pelo CONTEÚDO (magic bytes via finfo),
// NUNCA pela extensão declarada. PDF é só PDF, imagem é só imagem.
// Executável, script embutido, polyglot ou qualquer suspeita = REJEITADO.
//
// Todos os valores são ajustáveis por .env — NUNCA hardcodar.
// =============================================================================

return [

    // Disco padrão de destino (Flysystem). Em produção: 's3' apontando para o
    // Cloudflare R2 (S3-compatível). Em dev/testes: 'local' — o disco privado
    // que toda aplicação Laravel tem, entregue por URL assinada (o pacote liga
    // a entrega assinada nele, ver `protections`). O SecureUploadService
    // aceita disco por chamada; este é apenas o default.
    'disk' => env('UPLOADS_DISK', env('FILESYSTEM_DISK', 'local')),

    // Diretório base dentro do disco quando o chamador não informa um.
    'directory' => env('UPLOADS_DIRECTORY', 'uploads'),

    // Validade das URLs temporárias assinadas (documentos NUNCA em bucket
    // público — acesso sempre por URL assinada de curta duração).
    'temporary_url_minutes' => (int) env('UPLOADS_TEMPORARY_URL_MINUTES', 15),

    // Tipos permitidos por padrão quando o chamador não restringe.
    // Valores: chaves do mapa `types` abaixo.
    'allowed_types' => array_filter(explode(',', (string) env('UPLOADS_ALLOWED_TYPES', 'image,pdf'))),

    // --- Catálogo de tipos -----------------------------------------------------
    // mimes: allowlist de MIME REAL (finfo) → extensão canônica gerada.
    //        A extensão final do arquivo NUNCA vem do nome original: é
    //        derivada do MIME real detectado.
    // max_kb: tamanho máximo POR TIPO (a validação de formulário é do
    //        chamador, via Form Request; este é o limite de segurança final).
    // reencode: re-gera a imagem via GD antes de persistir (elimina qualquer
    //        payload embutido em metadados/trailing data — decisão documentada
    //        em docs/uploads.md).
    // max_pixels: teto de largura×altura (proteção contra "decompression
    //        bomb" antes de decodificar com a GD).
    'types' => [
        'image' => [
            'mimes' => [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ],
            'max_kb' => (int) env('UPLOADS_IMAGE_MAX_KB', 5120),
            'reencode' => true,
            'max_pixels' => (int) env('UPLOADS_IMAGE_MAX_PIXELS', 25000000),
        ],
        'pdf' => [
            'mimes' => [
                'application/pdf' => 'pdf',
            ],
            'max_kb' => (int) env('UPLOADS_PDF_MAX_KB', 10240),
        ],
    ],

    // --- Entrega por URL assinada (pacote twstec/kit-uploads) -----------------
    // PROTEÇÃO que o pacote liga sozinho no disco padrão de uploads, quando ele
    // é local (mesmo que o disco a declare desligada): a entrega assinada do
    // Laravel (`serve`), que responde só a URL assinada e dentro da validade.
    // Desligar só é seguro se a aplicação entregar os arquivos por conta
    // própria sem expô-los — e fica registrado no log a cada boot. A validação pelo conteúdo, o limite por tipo e o
    // reprocessamento de imagem moram no domínio e não são desligáveis.
    'protections' => env('UPLOADS_PROTECTIONS', true),

    // --- API v1 (pacote twstec/kit-uploads) ------------------------------------
    'api' => [
        // POST /api/v1/uploads. Com `enabled` = false o pacote não registra a
        // rota e a aplicação chama Twstec\Kit\Uploads\Http\UploadRoutes::register()
        // onde quiser. Prefixo, middleware e nomes nulos = os mesmos das rotas
        // v1 do twstec/kit-accounts (`api_keys.api.routes`): o upload fica no
        // MESMO grupo das outras rotas da API. A autenticação por chave
        // (`resolve.tenant`) entra sempre.
        'routes' => [
            'enabled' => env('UPLOADS_API_ROUTES', true),
            'prefix' => null,
            'middleware' => null,
            'name' => null,
        ],
    ],

    // --- Migração dos uploads para as contas (twstec/kit-uploads) ------------
    // Tamanho da faixa de ids de cada UPDATE da migration que passa os uploads
    // antigos (dono = pessoa) para as contas.
    'migration' => [
        'chunk' => (int) env('UPLOADS_MIGRATION_CHUNK', 1000),
    ],

    // --- Limpeza: uploads sem dono (twstec/kit-uploads) -----------------------
    // O comando `uploads:prune-orphans` (--dry-run só conta) apaga registro e
    // arquivo de: órfãos da migração para contas (dono excluído antes de os
    // uploads saírem junto), depois de `orphans_after_days`; fotos pessoais
    // que não são a foto de ninguém (a trocada, a de um formulário não
    // salvo), depois de `personal_after_hours`; e arquivos nas pastas de
    // upload sem registro no banco, mais velhos que `stray_files_after_hours`
    // (o prazo protege o envio em andamento). Cada rodada que apaga algo vai
    // para a trilha de auditoria (só contagens).
    //
    // `schedule`: expressão cron (UTC) do agendamento que o PACOTE registra.
    // Vazio desliga o agendamento — com aviso no log a cada boot (o comando
    // continua disponível para rodar à mão).
    'prune' => [
        'schedule' => env('UPLOADS_PRUNE_SCHEDULE', '40 3 * * *'),
        'orphans_after_days' => (int) env('UPLOADS_PRUNE_ORPHANS_AFTER_DAYS', 30),
        'personal_after_hours' => (int) env('UPLOADS_PRUNE_PERSONAL_AFTER_HOURS', 24),
        'stray_files_after_hours' => (int) env('UPLOADS_PRUNE_STRAY_FILES_AFTER_HOURS', 24),
        // Pastas (dentro do disco) onde procurar arquivo sem registro.
        'directories' => array_values(array_filter(explode(',', (string) env('UPLOADS_PRUNE_DIRECTORIES', 'uploads,avatars')))),
        // Discos varridos; vazio = só o disco padrão de uploads.
        'disks' => array_values(array_filter(explode(',', (string) env('UPLOADS_PRUNE_DISKS', '')))),
    ],

];
