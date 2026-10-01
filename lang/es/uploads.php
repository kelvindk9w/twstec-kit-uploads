<?php

declare(strict_types=1);

// Cadenas del módulo de Uploads Seguros (es). Siempre via __().

return [

    'stored' => 'Archivo subido con éxito.',
    'avatar_updated' => 'Avatar actualizado con éxito.',
    'outside_account' => 'Este archivo no pertenece a la cuenta actual.',

    // Rechazos de la validación de seguridad (SecureUploadService). Los
    // mensajes son deliberadamente genéricos: informan al usuario sin enseñar
    // al atacante a evadir la validación. El motivo técnico queda en el log.
    'rejected' => [
        'empty' => 'El archivo enviado está vacío.',
        'executable' => 'El tipo de archivo enviado no está permitido.',
        'mime_undetectable' => 'No fue posible identificar el tipo del archivo.',
        'mime_not_allowed' => 'El tipo de archivo enviado no está permitido.',
        'extension_mismatch' => 'La extensión del archivo no corresponde a su contenido.',
        'embedded_script' => 'El archivo contiene contenido no permitido.',
        'pdf_auto_action' => 'El PDF contiene acciones automáticas o scripts, que no están permitidos.',
        'image_undecodable' => 'La imagen enviada está corrupta o no es válida.',
        'image_too_many_pixels' => 'La imagen excede las dimensiones máximas permitidas.',
        'image_reencode_unavailable' => 'No fue posible procesar la imagen en este momento.',
        'image_reencode_failed' => 'No fue posible procesar la imagen enviada.',
        'too_large' => 'El archivo excede el tamaño máximo permitido de :max KB.',
        'unreadable' => 'No se pudo leer el archivo enviado.',
    ],

    // Uploads confidenciais (cifrados): entrega e recusas. O motivo vai para a
    // trilha de auditoria; a chave nunca aparece.
    'confidential' => [
        'unavailable' => 'Los documentos confidenciales no están disponibles en este momento: el cifrado no está configurado. No se guardó nada.',
        'unavailable_sodium' => 'Los documentos confidenciales no están disponibles: falta la extensión sodium de PHP (ext-sodium) en este servidor. No se guardó nada.',
        'no_actor' => 'Un documento confidencial solo lo abre una persona identificada.',
        'invalid_signature' => 'Enlace de documento confidencial inválido o vencido.',
        'not_found' => 'Documento confidencial no encontrado.',
        'actor_inactive' => 'Quien generó el enlace ya no existe o está bloqueado.',
        'no_longer_allowed' => 'Quien generó el enlace ya no tiene acceso a este documento (otra cuenta o acceso retirado).',
        'file_missing' => 'El archivo del documento confidencial no está en el almacenamiento.',
    ],

    // Retenção legal ("guardar até").
    'legal_hold' => [
        'reason_invalid' => 'Indique el motivo de la retención (hasta 160 caracteres).',
        'until_in_past' => 'La fecha de la retención debe estar en el futuro.',
        'erasure_refused' => 'El archivo :code está bajo retención legal hasta :until (:reason) y no se borró: quedó desvinculado y se borrará cuando venza el plazo.',
        'blocks_deletion' => '{1} Hay :count archivo bajo retención legal: la eliminación queda para después del plazo de la retención.|[2,*] Hay :count archivos bajo retención legal: la eliminación queda para después del plazo de la retención.',
    ],

];
