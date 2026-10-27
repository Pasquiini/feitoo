<?php

namespace App\Services\Quotations;

use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationValidationException;

class QuotationDraftDocument
{
    public static function canonical(array $editor): array
    {
        $decimal = static fn ($text) => is_string($text) ? str_replace(',', '.', trim($text)) : $text;
        $input = ['schema_version' => 1, 'currency' => 'BRL',
            'number' => $editor['number'] ?? null, 'issue_date' => $editor['issue_date'] ?? null,
            'valid_until' => $editor['valid_until'] ?? null, 'issuer' => $editor['issuer'] ?? null,
            'customer' => $editor['customer'] ?? null, 'items' => array_map(static fn ($item) => [
                'description' => $item['description'] ?? null, 'quantity' => $decimal($item['quantity'] ?? null),
                'unit_price_cents' => $item['unit_price_cents'] ?? null,
            ], $editor['items'] ?? []),
            'discount' => ['type' => $editor['discount_type'] ?? null, 'value' => ($editor['discount_type'] ?? null) === 'percent'
                ? $decimal($editor['discount_percent'] ?? null) : ($editor['discount_fixed_cents'] ?? null)],
            'conditions' => $editor['conditions'] ?? '', 'notes' => $editor['notes'] ?? '',
            'template_id' => $editor['template_id'] ?? null];
        // Display-only currency text is never used in arithmetic. Inconsistent text must not be emitted silently.
        if (isset($editor['money_inputs'])) {
            $texts = $editor['money_inputs'];
            foreach ($texts['prices'] as $i => $text) {
                if (! isset($input['items'][$i]) || ! self::moneyMatches($text, $input['items'][$i]['unit_price_cents'])) {
                    throw new QuotationValidationException(['items' => ['Confira os valores dos itens.']]);
                }
            }
            if ($input['discount']['type'] === 'fixed' && ! self::moneyMatches($texts['fixed_discount'], $input['discount']['value'])) {
                throw new QuotationValidationException(['discount' => ['Confira o desconto.']]);
            }
        }
        Quotation::fromArray($input);

        return $input;
    }

    private static function moneyMatches(string $text, mixed $cents): bool
    {
        if (! is_int($cents)) {
            return false;
        }
        $text = preg_replace('/^R\$\s*/u', '', trim($text));
        if (strlen($text) > 24 || ! preg_match('/^(?:0|[1-9]\d*|[1-9]\d{0,2}(?:\.\d{3})+)(?:,\d{0,2})?$/D', $text)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode(',', str_replace('.', '', $text)), 2, '');
        $digits = ltrim($whole.str_pad($fraction, 2, '0'), '0');

        return ($digits === '' ? '0' : $digits) === (string) $cents;
    }
}
