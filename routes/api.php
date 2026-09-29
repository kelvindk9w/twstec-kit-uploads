<?php

declare(strict_types=1);

use Twstec\Kit\Uploads\Http\UploadRoutes;

// =============================================================================
// Rota de upload da API v1 do pacote (POST /api/v1/uploads), carregada pelo
// UploadsServiceProvider quando `uploads.api.routes.enabled` é true (o
// padrão). Mesmo grupo das rotas v1 do twstec/kit-accounts; a autenticação por
// chave (`resolve.tenant`) entra sempre. Ver Http\UploadRoutes.
// =============================================================================

UploadRoutes::register();
