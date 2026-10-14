<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

final readonly class FiscalParty
{
    public function __construct(
        public ?string $cnpj,
        public ?string $cpf,
        public ?string $name,
        public ?string $tradeName,
        public ?string $stateRegistration,
        public ?string $taxRegime,
        public ?string $stateRegistrationIndicator,
        public ?string $email,
        public ?FiscalAddress $address,
    ) {}
}
