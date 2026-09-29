<?php

declare(strict_types=1);

// Strings do módulo de Uploads Seguros (pt-BR). Sempre via __().

return [

    'stored' => 'Arquivo enviado com sucesso.',
    'avatar_updated' => 'Avatar atualizado com sucesso.',
    'outside_account' => 'Este arquivo não pertence à conta atual.',

    // Rejeições da validação de segurança (SecureUploadService). As mensagens
    // são deliberadamente genéricas: informam o usuário sem ensinar o
    // atacante a contornar a validação. O motivo técnico fica no log.
    'rejected' => [
        'empty' => 'O arquivo enviado está vazio.',
        'executable' => 'O tipo de arquivo enviado não é permitido.',
        'mime_undetectable' => 'Não foi possível identificar o tipo do arquivo.',
        'mime_not_allowed' => 'O tipo de arquivo enviado não é permitido.',
        'extension_mismatch' => 'A extensão do arquivo não corresponde ao seu conteúdo.',
        'embedded_script' => 'O arquivo contém conteúdo não permitido.',
        'pdf_auto_action' => 'O PDF contém ações automáticas ou scripts, que não são permitidos.',
        'image_undecodable' => 'A imagem enviada está corrompida ou é inválida.',
        'image_too_many_pixels' => 'A imagem excede as dimensões máximas permitidas.',
        'image_reencode_unavailable' => 'Não foi possível processar a imagem no momento.',
        'image_reencode_failed' => 'Não foi possível processar a imagem enviada.',
        'too_large' => 'O arquivo excede o tamanho máximo permitido de :max KB.',
        'unreadable' => 'Não foi possível ler o arquivo enviado.',
    ],

];
