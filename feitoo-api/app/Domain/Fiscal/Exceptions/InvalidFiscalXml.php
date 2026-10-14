<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Exceptions;

use RuntimeException;

final class InvalidFiscalXml extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('XML fiscal inválido ou campo com formato inconsistente.');
    }
}
