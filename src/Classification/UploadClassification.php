<?php

declare(strict_types=1);

namespace Twstec\Kit\Uploads\Classification;

/**
 * CLASSIFICAÇÃO do upload pela FINALIDADE — declarada pelo projeto em cada
 * chamada do SecureUploadService (ou pelo padrão `uploads.classification.default`).
 *
 * - Public: conteúdo que o projeto pode mostrar a qualquer um que tenha o
 *   link (a imagem de um produto, por exemplo). Mesmo armazenamento e mesma
 *   entrega por URL assinada de curta duração do Private: o kit nunca põe
 *   upload em bucket público. A classificação registra a intenção.
 * - Private (o padrão, e o de todo upload anterior a ela): da conta, entregue
 *   por URL assinada de curta duração.
 * - Confidential: documento de identificação, contrato, comprovante.
 *   CIFRADO antes de ir ao armazenamento (Confidential\ConfidentialStorage),
 *   com chave própria separada da APP_KEY; entregue SÓ pela rota da
 *   aplicação, que decifra em fluxo; gerar URL, visualizar e baixar vão para
 *   a trilha de auditoria (Confidential\ConfidentialAccess). Sem a chave, o
 *   upload confidencial é recusado (falha fechada).
 */
enum UploadClassification: string
{
    case Public = 'public';
    case Private = 'private';
    case Confidential = 'confidential';

    /**
     * O padrão da configuração; valor desconhecido = falha fechada (exceção),
     * nunca um rebaixamento silencioso.
     */
    public static function default(): self
    {
        return self::from((string) config('uploads.classification.default', self::Private->value));
    }

    public function isConfidential(): bool
    {
        return $this === self::Confidential;
    }
}
