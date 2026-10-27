<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

final readonly class QuotationTotals
{
    public function __construct(
        public int $subtotalCents,
        public int $discountCents,
        public int $totalCents,
    ) {}
}
