<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Exceptions;

use RuntimeException;

final class UnsupportedFiscalDocument extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Documento fiscal não suportado; esperado NF-e modelo 55.');
    }
}
