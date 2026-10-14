<?php

declare(strict_types=1);

namespace App\Domain\Tools\ProductsFromXml;

use App\Domain\Fiscal\DTO\ParsedFiscalDocument;

final readonly class ProductItem
{
    public function __construct(
        public string $invoice_key,
        public string $note_number,
        public string $series,
        public string $issued_at,
        public string $issuer_name,
        public string $issuer_document,
        public ?string $code,
        public string $product,
        public string $ncm,
        public string $cfop,
        public string $unit,
        public string $quantity,
        public ?string $unit_value,
        public string $total_value,
    ) {}

    /** @return list<self> */
    public static function fromDocument(ParsedFiscalDocument $document): array
    {
        return array_map(fn ($item) => new self(
            $document->accessKey, $document->number, $document->series,
            $document->issuedAt->format($document->identification->issuedAtHasTime ? 'c' : 'Y-m-d'),
            $document->issuer->name, $document->issuer->cnpj ?? $document->issuer->cpf,
            $item->productCode === '' ? null : $item->productCode, $item->description, $item->ncm,
            $item->cfop, $item->commercialUnit, $item->commercialQuantity,
            $item->commercialUnitPrice, $item->productTotal,
        ), $document->items);
    }

    public function preview(): array
    {
        $fields = get_object_vars($this);
        unset($fields['invoice_key']);
        foreach (['product', 'issuer_name', 'code', 'unit'] as $field) {
            if ($fields[$field] !== null) {
                $fields[$field] = mb_strimwidth($fields[$field], 0, 255, '…', 'UTF-8');
            }
        }

        return $fields;
    }
}
