<?php

declare(strict_types=1);

namespace App\Services\Quotations;

use RuntimeException;

final class QuotationPdfException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Não foi possível gerar o PDF do orçamento com segurança.');
    }
}
