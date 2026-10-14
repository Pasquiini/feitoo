<?php

declare(strict_types=1);

namespace App\Domain\Tools\ProductsFromXml;

final readonly class ConsolidatedProduct
{
    public function __construct(
        public ?string $code,
        public string $product,
        public string $issuer_document,
        public string $issuer_name,
        public string $ncm,
        public string $unit,
        public string $total_quantity,
        public int $invoice_count,
        public ?string $minimum_unit_value,
        public ?string $maximum_unit_value,
        public ?string $average_unit_value,
        public string $total_value,
    ) {}

    public function preview(): array
    {
        $fields = get_object_vars($this);
        foreach (['product', 'issuer_name', 'code', 'unit'] as $field) {
            if ($fields[$field] !== null) {
                $fields[$field] = mb_strimwidth($fields[$field], 0, 255, '…', 'UTF-8');
            }
        }

        return $fields;
    }
}
