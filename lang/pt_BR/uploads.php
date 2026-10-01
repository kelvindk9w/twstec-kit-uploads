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

    // Uploads confidenciais (cifrados): entrega e recusas. O motivo vai para a
    // trilha de auditoria; a chave nunca aparece.
    'confidential' => [
        'unavailable' => 'Documentos confidenciais estão indisponíveis no momento: a cifra não está configurada. Nada foi gravado.',
        'unavailable_sodium' => 'Documentos confidenciais estão indisponíveis: falta a extensão sodium do PHP (ext-sodium) neste servidor. Nada foi gravado.',
        'no_actor' => 'Documento confidencial só é aberto por alguém identificado.',
        'invalid_signature' => 'Link de documento confidencial inválido ou vencido.',
        'not_found' => 'Documento confidencial não encontrado.',
        'actor_inactive' => 'Quem gerou o link não existe mais ou está bloqueado.',
        'no_longer_allowed' => 'Quem gerou o link não tem mais acesso a este documento (conta diferente ou acesso retirado).',
        'file_missing' => 'O arquivo do documento confidencial não está no armazenamento.',
    ],

    // Retenção legal ("guardar até").
    'legal_hold' => [
        'reason_invalid' => 'Informe o motivo da guarda (até 160 caracteres).',
        'until_in_past' => 'A data da guarda precisa estar no futuro.',
        'erasure_refused' => 'O arquivo :code está sob guarda legal até :until (:reason) e não foi apagado: ficou desvinculado e será apagado quando o prazo vencer.',
        'blocks_deletion' => '{1} Há :count arquivo sob guarda legal: a exclusão fica para depois do prazo da guarda.|[2,*] Há :count arquivos sob guarda legal: a exclusão fica para depois do prazo da guarda.',
    ],

];
