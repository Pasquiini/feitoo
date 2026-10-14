<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

final readonly class FiscalAddress
{
    public function __construct(
        public ?string $street,
        public ?string $number,
        public ?string $complement,
        public ?string $district,
        public ?string $municipalityCode,
        public ?string $municipality,
        public ?string $state,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?string $country,
        public ?string $phone,
    ) {}
}
