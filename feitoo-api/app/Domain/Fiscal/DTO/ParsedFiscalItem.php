<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

final readonly class ParsedFiscalItem
{
    /** @param list<FiscalTaxGroup> $taxes */
    public function __construct(
        public int $number,
        public string $productCode,
        public string $description,
        public string $ncm,
        public string $cfop,
        public string $commercialUnit,
        public string $commercialQuantity,
        public ?string $commercialUnitPrice,
        public string $productTotal,
        public ?string $gtin,
        public ?string $cest,
        public ?string $taxableGtin,
        public ?string $taxableUnit,
        public ?string $taxableQuantity,
        public ?string $taxableUnitPrice,
        public ?string $includedInTotal,
        public ?string $purchaseOrder,
        public ?string $purchaseOrderItem,
        public ?string $approximateTaxTotal,
        public array $taxes,
    ) {}
}
