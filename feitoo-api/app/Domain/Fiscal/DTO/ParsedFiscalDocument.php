<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\DTO;

use DateTimeImmutable;

final readonly class ParsedFiscalDocument
{
    /** @param list<ParsedFiscalItem> $items
     * @param  list<FiscalPayment>  $payments
     */
    public function __construct(
        public FiscalDocumentType $documentType,
        public string $model,
        public string $version,
        public string $accessKey,
        public string $number,
        public string $series,
        public DateTimeImmutable $issuedAt,
        public ?string $operationNature,
        public FiscalIdentification $identification,
        public FiscalParty $issuer,
        public ?FiscalParty $recipient,
        public array $items,
        public ?FiscalTotals $totals,
        public ?FiscalProtocol $protocol,
        public array $payments,
    ) {}
}
