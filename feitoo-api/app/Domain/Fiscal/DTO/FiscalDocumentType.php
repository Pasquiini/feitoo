<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

enum FiscalDocumentType: string
{
    case Nfe = 'nfe';
}
