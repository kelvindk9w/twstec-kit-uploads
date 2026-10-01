<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Twstec\Kit\Uploads\Confidential\ConfidentialAccess;
use Twstec\Kit\Uploads\Confidential\Http\ConfidentialDownloadController;

// =============================================================================
// Entrega dos uploads CONFIDENCIAIS (decifra em fluxo), carregada SEMPRE pelo
// UploadsServiceProvider: o arquivo confidencial só sai por aqui. Sem sessão
// (a URL assinada e curta é a credencial, amarrada a quem a gerou — ver
// Confidential\ConfidentialAccess), limitada por IP.
// =============================================================================

Route::get(trim((string) config('uploads.confidential.route.prefix', 'uploads/confidential'), '/').'/{upload}', ConfidentialDownloadController::class)
    ->whereUuid('upload')
    ->middleware('throttle:'.ConfidentialAccess::ROUTE)
    ->name(ConfidentialAccess::ROUTE);
