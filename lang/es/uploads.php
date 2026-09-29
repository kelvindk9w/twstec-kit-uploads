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

];
