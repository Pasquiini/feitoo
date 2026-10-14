<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

final readonly class FiscalTotals
{
    public function __construct(
        public ?string $vBC,
        public ?string $vICMS,
        public ?string $vICMSDeson,
        public ?string $vFCP,
        public ?string $vBCST,
        public ?string $vST,
        public ?string $vFCPST,
        public ?string $vProd,
        public ?string $vFrete,
        public ?string $vSeg,
        public ?string $vDesc,
        public ?string $vII,
        public ?string $vIPI,
        public ?string $vIPIDevol,
        public ?string $vPIS,
        public ?string $vCOFINS,
        public ?string $vOutro,
        public ?string $vNF,
        public ?string $vTotTrib,
    ) {}
}
