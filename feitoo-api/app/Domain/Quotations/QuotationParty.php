<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

final readonly class QuotationParty
{
    public function __construct(
        public string $name,
        public string $document = '',
        public string $email = '',
        public string $phone = '',
        public string $address = '',
    ) {}
}
