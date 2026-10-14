<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

final readonly class FiscalPayment
{
    public function __construct(
        public ?string $type,
        public ?string $amount,
    ) {}
}
