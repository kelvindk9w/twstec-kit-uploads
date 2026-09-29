<?php

declare(strict_types=1);

// Secure Uploads module strings (en). Always via __().

return [

    'stored' => 'File uploaded successfully.',
    'avatar_updated' => 'Avatar updated successfully.',
    'outside_account' => 'This file does not belong to the current account.',

    // Security validation rejections (SecureUploadService). The messages are
    // deliberately generic: they inform the user without teaching an attacker
    // how to bypass validation. The technical reason stays in the log.
    'rejected' => [
        'empty' => 'The uploaded file is empty.',
        'executable' => 'The uploaded file type is not allowed.',
        'mime_undetectable' => 'Could not identify the file type.',
        'mime_not_allowed' => 'The uploaded file type is not allowed.',
        'extension_mismatch' => 'The file extension does not match its content.',
        'embedded_script' => 'The file contains disallowed content.',
        'pdf_auto_action' => 'The PDF contains automatic actions or scripts, which are not allowed.',
        'image_undecodable' => 'The uploaded image is corrupted or invalid.',
        'image_too_many_pixels' => 'The image exceeds the maximum allowed dimensions.',
        'image_reencode_unavailable' => 'Could not process the image right now.',
        'image_reencode_failed' => 'Could not process the uploaded image.',
        'too_large' => 'The file exceeds the maximum allowed size of :max KB.',
        'unreadable' => 'The uploaded file could not be read.',
    ],

];
