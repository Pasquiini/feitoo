<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

final readonly class Quotation
{
    /** @param list<QuotationItem> $items */
    private function __construct(
        public string $number,
        public string $issueDate,
        public string $validUntil,
        public QuotationParty $issuer,
        public QuotationParty $customer,
        public array $items,
        public string $discountType,
        public string|int $discountValue,
        public QuotationTotals $totals,
        public string $conditions,
        public string $notes,
        public QuotationTemplate $template,
    ) {}

    public static function fromArray(array $input): self
    {
        $data = (new QuotationValidator)->validate($input);
        $calculator = new QuotationCalculator;
        $items = array_map(fn (array $item) => new QuotationItem($item['description'], $item['quantity'], $item['unit_price_cents'], $calculator->line($item['quantity'], $item['unit_price_cents'])), $data['items']);
        $party = static fn (array $value) => new QuotationParty($value['name'], $value['document'] ?? '', $value['email'] ?? '', $value['phone'] ?? '', $value['address'] ?? '');

        return new self($data['number'], $data['issue_date'], $data['valid_until'], $party($data['issuer']), $party($data['customer']), $items,
            $data['discount']['type'], $data['discount']['value'], $calculator->totals($items, $data['discount']['type'], $data['discount']['value']),
            $data['conditions'], $data['notes'], QuotationTemplate::from($data['template_id']));
    }
}
