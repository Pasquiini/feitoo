<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Exceptions;

use RuntimeException;

final class MissingRequiredFiscalField extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('NF-e estruturalmente incompleta: '.$field.'.');
    }
}
