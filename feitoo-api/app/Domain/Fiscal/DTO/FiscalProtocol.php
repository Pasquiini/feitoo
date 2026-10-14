<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

use DateTimeImmutable;

final readonly class FiscalProtocol
{
    public function __construct(
        public ?string $environment,
        public ?string $applicationVersion,
        public string $accessKey,
        public ?DateTimeImmutable $receivedAt,
        public ?string $number,
        public ?string $digest,
        public ?string $statusCode,
        public ?string $reason,
    ) {}
}
