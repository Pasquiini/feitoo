<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

final readonly class QuotationItem
{
    public function __construct(
        public string $description,
        public string $quantity,
        public int $unitPriceCents,
        public int $totalCents,
    ) {}
}
