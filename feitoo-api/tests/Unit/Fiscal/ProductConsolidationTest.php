<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Domain\Tools\ProductsFromXml\ProductConsolidationService;
use App\Domain\Tools\ProductsFromXml\ProductItem;
use PHPUnit\Framework\TestCase;

class ProductConsolidationTest extends TestCase
{
    private function item(array $overrides = []): ProductItem
    {
        return new ProductItem(...array_replace(['invoice_key' => 'invoice-1', 'note_number' => '001', 'series' => '1',
            'issued_at' => '2026-10-01', 'issuer_name' => 'Fornecedor', 'issuer_document' => '00000000000100',
            'code' => '001234', 'product' => 'Café original', 'ncm' => '09012100', 'cfop' => '5102', 'unit' => 'UN',
            'quantity' => '2.00', 'unit_value' => '10.00', 'total_value' => '20.00'], $overrides));
    }

    public function test_repeated_product_in_ten_invoices_has_exact_quantity_total_and_frequency(): void
    {
        $items = array_map(fn ($n) => $this->item(['invoice_key' => 'invoice-'.$n]), range(1, 10));
        $products = (new ProductConsolidationService)->consolidate($items);
        $this->assertCount(1, $products);
        $this->assertSame('20.00', $products[0]->total_quantity);
        $this->assertSame('200.00', $products[0]->total_value);
        $this->assertSame(10, $products[0]->invoice_count);
        $this->assertSame('001234', $products[0]->code);
    }

    public function test_same_code_in_different_suppliers_ncm_or_units_never_merges(): void
    {
        $products = (new ProductConsolidationService)->consolidate([$this->item(),
            $this->item(['issuer_document' => '00000000000200']), $this->item(['ncm' => '12345678']),
            $this->item(['unit' => 'KG'])]);
        $this->assertCount(4, $products);
    }

    public function test_same_code_with_different_descriptions_keeps_first_display_and_counts_invoice_once(): void
    {
        $products = (new ProductConsolidationService)->consolidate([$this->item(), $this->item(['product' => 'Outra descrição'])]);
        $this->assertCount(1, $products);
        $this->assertSame('Café original', $products[0]->product);
        $this->assertSame(1, $products[0]->invoice_count);
    }

    public function test_missing_code_uses_description_without_removing_accents_or_merging_suppliers(): void
    {
        $products = (new ProductConsolidationService)->consolidate([$this->item(['code' => null]),
            $this->item(['code' => '', 'product' => '  CAFÉ   ORIGINAL  ']),
            $this->item(['code' => null, 'product' => 'Cafe original']),
            $this->item(['code' => null, 'issuer_document' => '00000000000200'])]);
        $this->assertCount(3, $products);
        $this->assertSame('Café original', $products[0]->product);
        $this->assertSame('40.00', $products[0]->total_value);
    }

    public function test_weighted_average_minimum_maximum_and_fractional_quantities(): void
    {
        $products = (new ProductConsolidationService)->consolidate([$this->item(['quantity' => '0.20', 'total_value' => '2.00']),
            $this->item(['quantity' => '0.30', 'unit_value' => '20.00', 'total_value' => '6.00'])]);
        $this->assertSame('0.50', $products[0]->total_quantity);
        $this->assertSame('8.00', $products[0]->total_value);
        $this->assertSame('16.0000000000', $products[0]->average_unit_value);
        $this->assertSame('10.00', $products[0]->minimum_unit_value);
        $this->assertSame('20.00', $products[0]->maximum_unit_value);
    }

    public function test_large_decimals_are_exact_and_products_sort_by_value_descending(): void
    {
        $products = (new ProductConsolidationService)->consolidate([$this->item(['code' => 'small']),
            $this->item(['total_value' => '9007199254740993.10']), $this->item(['total_value' => '0.19'])]);
        $this->assertSame('9007199254740993.29', $products[0]->total_value);
        $this->assertSame('small', $products[1]->code);
    }

    public function test_zero_quantity_and_missing_unit_prices_do_not_invent_zero(): void
    {
        $product = (new ProductConsolidationService)->consolidate([$this->item(['quantity' => '0', 'unit_value' => null])])[0];
        $this->assertNull($product->average_unit_value);
        $this->assertNull($product->minimum_unit_value);
        $this->assertNull($product->maximum_unit_value);
    }

    public function test_missing_unit_price_is_ignored_but_zero_price_is_valid_and_codes_remain_text(): void
    {
        $products = (new ProductConsolidationService)->consolidate([$this->item(['unit_value' => null]),
            $this->item(['unit_value' => '0.00']), $this->item(['unit_value' => '12.50']),
            $this->item(['code' => '1234'])]);
        $this->assertCount(2, $products);
        $this->assertSame('0.00', $products[0]->minimum_unit_value);
        $this->assertSame('12.50', $products[0]->maximum_unit_value);
        $this->assertSame('001234', $products[0]->code);
    }
}
