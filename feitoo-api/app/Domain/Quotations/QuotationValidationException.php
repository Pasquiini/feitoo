<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use InvalidArgumentException;

final class QuotationValidationException extends InvalidArgumentException
{
    /** @param array<string, list<string>> $errors Field names and safe messages only. */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Dados do orçamento inválidos.');
    }
}
