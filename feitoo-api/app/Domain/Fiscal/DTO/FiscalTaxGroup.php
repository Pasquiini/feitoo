<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

final readonly class FiscalTaxGroup
{
    public function __construct(
        public FiscalTaxType $type,
        public FiscalTaxElement $data,
    ) {}
}
