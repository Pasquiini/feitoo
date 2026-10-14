<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

enum FiscalTaxType: string
{
    case Icms = 'ICMS';
    case Ipi = 'IPI';
    case Pis = 'PIS';
    case Cofins = 'COFINS';
    case Ii = 'II';
    case Issqn = 'ISSQN';
}
