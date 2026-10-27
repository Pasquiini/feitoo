<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class QuotationGenerationException extends HttpException
{
    public function __construct(public readonly string $reason, int $status = 409)
    {
        parent::__construct($status, match ($reason) {
            'free_quota_exhausted' => 'Você já utilizou seu orçamento gratuito de hoje.',
            'pro_first' => 'Sua quota Pro será utilizada primeiro. Créditos pagos permanecem preservados.',
            'pro_quota_exhausted' => 'Sua quota Pro de orçamentos neste período foi utilizada. Escolha explicitamente um crédito para continuar.',
            'pro_period_invalid' => 'Não há benefício Pro vigente para esta reserva.',
            'credits_exhausted' => 'Não há créditos de orçamentos disponíveis.',
            'source_invalid' => 'Escolha uma origem de emissão disponível.',
            'checkout_unavailable' => 'A compra de créditos está temporariamente indisponível.',
            'payment_mismatch' => 'Não foi possível validar o pagamento deste pedido.',
            'quotation_invalid' => 'Confira os dados do orçamento antes de gerar o PDF.',
            'generation_in_progress' => 'Já existe um orçamento em geração nesta conta.',
            'generation_unavailable' => 'A geração de orçamentos está temporariamente indisponível.',
            'draft_revision_conflict' => 'O rascunho foi alterado. Recupere a revisão atual antes de gerar.',
            'generation_expired' => 'Esta emissão expirou. Edite o rascunho para solicitar uma nova emissão.',
            default => 'Não foi possível concluir a solicitação do orçamento.',
        });
    }
}
