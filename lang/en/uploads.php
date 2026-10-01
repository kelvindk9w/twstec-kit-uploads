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

    // Uploads confidenciais (cifrados): entrega e recusas. O motivo vai para a
    // trilha de auditoria; a chave nunca aparece.
    'confidential' => [
        'unavailable' => 'Confidential documents are unavailable right now: encryption is not configured. Nothing was stored.',
        'unavailable_sodium' => 'Confidential documents are unavailable: the PHP sodium extension (ext-sodium) is missing on this server. Nothing was stored.',
        'no_actor' => 'A confidential document can only be opened by an identified person.',
        'invalid_signature' => 'Invalid or expired confidential document link.',
        'not_found' => 'Confidential document not found.',
        'actor_inactive' => 'Whoever created the link no longer exists or is blocked.',
        'no_longer_allowed' => 'Whoever created the link no longer has access to this document (different account or access removed).',
        'file_missing' => 'The confidential document file is not in storage.',
    ],

    // Retenção legal ("guardar até").
    'legal_hold' => [
        'reason_invalid' => 'Enter the reason for the hold (up to 160 characters).',
        'until_in_past' => 'The hold date must be in the future.',
        'erasure_refused' => 'File :code is under legal hold until :until (:reason) and was not erased: it was detached and will be erased when the hold expires.',
        'blocks_deletion' => '{1} There is :count file under legal hold: deletion must wait until the hold expires.|[2,*] There are :count files under legal hold: deletion must wait until the hold expires.',
    ],

];
