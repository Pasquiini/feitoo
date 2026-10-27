<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class QuotationCalculator
{
    public function line(string $quantity, int $unitPriceCents): int
    {
        $value = BigDecimal::of($quantity)->multipliedBy($unitPriceCents)->toScale(0, RoundingMode::HALF_UP);
        if ($value->isGreaterThan(QuotationLimits::MAX_TOTAL_CENTS)) {
            throw new QuotationValidationException(['items' => ['Valor dos itens excede o limite.']]);
        }

        return $value->toInt();
    }

    /** @param list<QuotationItem> $items */
    public function totals(array $items, string $discountType, string|int $discountValue): QuotationTotals
    {
        $subtotal = BigDecimal::zero();
        foreach ($items as $item) {
            $subtotal = $subtotal->plus($item->totalCents);
        }
        if ($subtotal->isGreaterThan(QuotationLimits::MAX_TOTAL_CENTS)) {
            throw new QuotationValidationException(['items' => ['Subtotal excede o limite.']]);
        }
        $discount = $discountType === 'percent'
            ? $subtotal->multipliedBy($discountValue)->dividedBy(100, 0, RoundingMode::HALF_UP)
            : BigDecimal::of($discountValue);
        if ($discount->isGreaterThan($subtotal)) {
            throw new QuotationValidationException(['discount.value' => ['Desconto excede o subtotal.']]);
        }

        return new QuotationTotals($subtotal->toInt(), $discount->toInt(), $subtotal->minus($discount)->toInt());
    }

    public static function money(int $cents): string
    {
        // Integer grouping: no binary floating point, including during presentation.
        $integer = (string) intdiv($cents, 100);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

        return $grouped.','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function quantity(string $quantity): string
    {
        return str_replace('.', ',', str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity);
    }
}
