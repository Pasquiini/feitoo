<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

use DateTimeImmutable;

final readonly class FiscalIdentification
{
    public function __construct(
        public ?string $stateCode,
        public ?string $numericCode,
        public ?string $operationNature,
        public string $model,
        public string $series,
        public string $number,
        public DateTimeImmutable $issuedAt,
        public bool $issuedAtHasTime,
        public ?DateTimeImmutable $departedAt,
        public ?bool $departedAtHasTime,
        public ?string $operationType,
        public ?string $destinationIndicator,
        public ?string $taxableMunicipalityCode,
        public ?string $printType,
        public ?string $emissionType,
        public ?string $checkDigit,
        public ?string $environment,
        public ?string $purpose,
        public ?string $finalConsumerIndicator,
        public ?string $presenceIndicator,
        public ?string $emissionProcess,
        public ?string $processVersion,
    ) {}
}
