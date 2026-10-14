<?php

declare(strict_types=1);

namespace App\Domain\Tools\ProductsFromXml;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class ProductConsolidationService
{
    /** @param iterable<ProductItem> $items
     * @return list<ConsolidatedProduct>
     */
    public function consolidate(iterable $items): array
    {
        $groups = [];
        foreach ($items as $index => $item) {
            $code = $this->normalize($item->code ?? '');
            $description = $this->normalize($item->product);
            // Separate code and description namespaces. Missing identity stays separate.
            $identity = $code !== '' ? ['code', $code] : ($description !== '' ? ['description', $description] : ['item', $index]);
            $key = json_encode([$item->issuer_document ?: $item->invoice_key,
                $identity, $this->normalize($item->ncm), $this->normalize($item->unit)], JSON_THROW_ON_ERROR);
            $group = $groups[$key] ?? ['first' => $item, 'quantity' => BigDecimal::zero(), 'total' => BigDecimal::zero(),
                'invoices' => [], 'min' => null, 'max' => null];
            $group['quantity'] = $group['quantity']->plus($item->quantity);
            $group['total'] = $group['total']->plus($item->total_value);
            $group['invoices'][$item->invoice_key] = true;
            if ($item->unit_value !== null) {
                $unit = BigDecimal::of($item->unit_value);
                $group['min'] = $group['min'] === null || $unit->isLessThan($group['min']) ? $unit : $group['min'];
                $group['max'] = $group['max'] === null || $unit->isGreaterThan($group['max']) ? $unit : $group['max'];
            }
            $groups[$key] = $group;
        }
        $products = [];
        foreach ($groups as $group) {
            $item = $group['first'];
            $products[] = new ConsolidatedProduct($item->code, $item->product, $item->issuer_document,
                $item->issuer_name, $item->ncm, $item->unit, (string) $group['quantity'], count($group['invoices']),
                $group['min'] === null ? null : (string) $group['min'], $group['max'] === null ? null : (string) $group['max'],
                $group['quantity']->isZero() ? null : (string) $group['total']->dividedBy($group['quantity'], 10, RoundingMode::HALF_UP),
                (string) $group['total']);
        }
        // Stable insertion order resolves ties; original display fields are never normalized.
        usort($products, fn ($a, $b) => BigDecimal::of($b->total_value)->compareTo($a->total_value));

        return $products;
    }

    private function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)), 'UTF-8');
    }
}
