<?php

declare(strict_types=1);

namespace Tests\Unit\Quotations;

use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationCalculator;
use App\Domain\Quotations\QuotationValidationException;
use App\Support\Quotations\QuotationDemoFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    public function test_rounds_each_line_half_up_before_summing_and_discounting(): void
    {
        $input = QuotationDemoFixtures::simple();
        $input['items'] = [
            ['description' => 'Fração A', 'quantity' => '0.5', 'unit_price_cents' => 101],
            ['description' => 'Fração B', 'quantity' => '0.5', 'unit_price_cents' => 101],
            ['description' => 'Fração mínima', 'quantity' => '0.0001', 'unit_price_cents' => 5000],
        ];
        $input['discount'] = ['type' => 'percent', 'value' => '50.00'];
        $quotation = Quotation::fromArray($input);
        $this->assertSame([51, 51, 1], array_column($quotation->items, 'totalCents'));
        $this->assertSame(103, $quotation->totals->subtotalCents);
        $this->assertSame(52, $quotation->totals->discountCents);
        $this->assertSame(51, $quotation->totals->totalCents);
    }

    public function test_fractional_fixture_and_fixed_discount_are_exact(): void
    {
        $input = QuotationDemoFixtures::scenarios()['fractional']['data'];
        $input['discount'] = ['type' => 'fixed', 'value' => 1000];
        $quotation = Quotation::fromArray($input);
        $this->assertSame(33950, $quotation->items[0]->totalCents);
        $this->assertSame(34001, $quotation->totals->subtotalCents);
        $this->assertSame(33001, $quotation->totals->totalCents);
        $this->assertSame('1.000.000.000,00', QuotationCalculator::money(100000000000));
        $this->assertSame('0,01', QuotationCalculator::money(1));
    }

    public function test_free_item_optional_party_data_and_full_discount_are_valid(): void
    {
        $input = QuotationDemoFixtures::simple();
        $input['issuer'] = ['name' => 'Prestador Fictício'];
        $input['customer'] = ['name' => 'Cliente Fictício'];
        $input['items'][0]['unit_price_cents'] = 0;
        $input['conditions'] = $input['notes'] = '';
        $this->assertSame(0, Quotation::fromArray($input)->totals->totalCents);
        $input['items'][0]['unit_price_cents'] = 123;
        $input['discount'] = ['type' => 'fixed', 'value' => 123];
        $this->assertSame(0, Quotation::fromArray($input)->totals->totalCents);
        $input['discount'] = ['type' => 'percent', 'value' => '100'];
        $this->assertSame(0, Quotation::fromArray($input)->totals->totalCents);
    }

    public function test_total_ceiling_is_inclusive_and_checked_before_integer_conversion(): void
    {
        $input = QuotationDemoFixtures::simple();
        $input['items'][0]['quantity'] = '100';
        $input['items'][0]['unit_price_cents'] = 1000000000;
        $this->assertSame(100000000000, Quotation::fromArray($input)->totals->totalCents);
        $input['items'][0]['quantity'] = '1000000';
        $this->expectException(QuotationValidationException::class);
        Quotation::fromArray($input);
    }

    public static function invalidInputs(): iterable
    {
        yield 'missing party' => ['issuer', []];
        yield 'unknown top-level HTML' => ['html', '<html>'];
        yield 'user total is forbidden' => ['total_cents', 1];
        yield 'plan price is forbidden' => ['plan_price', 1];
        yield 'external logo is forbidden' => ['logo_url', 'https://example.test/a.png'];
        yield 'local path is forbidden' => ['logo_path', 'C:/private/logo.png'];
        yield 'nested unknown key' => ['issuer.html', '<b>name</b>'];
        yield 'nested item subtotal' => ['items.0.subtotal', 1];
        yield 'missing number' => ['number', ''];
        yield 'multiline name' => ['issuer.name', "Nome\nNome"];
        yield 'invalid email' => ['customer.email', 'invalid-email'];
        yield 'invalid date' => ['issue_date', '2026-02-30'];
        yield 'invalid date format' => ['issue_date', '20/10/2026'];
        yield 'validity before issue' => ['valid_until', '2026-01-01'];
        yield 'invalid template' => ['template_id', '../secret'];
        yield 'currency' => ['currency', 'USD'];
        yield 'zero quantity' => ['items.0.quantity', '0'];
        yield 'negative quantity' => ['items.0.quantity', '-1'];
        yield 'quantity float' => ['items.0.quantity', 1.25];
        yield 'quantity integer' => ['items.0.quantity', 1];
        yield 'too many decimal places' => ['items.0.quantity', '1.00001'];
        yield 'quantity exponent' => ['items.0.quantity', '1e4'];
        yield 'quantity comma' => ['items.0.quantity', '1,25'];
        yield 'quantity leading zero' => ['items.0.quantity', '01.25'];
        yield 'quantity limit' => ['items.0.quantity', '1000000.0001'];
        yield 'negative price' => ['items.0.unit_price_cents', -1];
        yield 'float price' => ['items.0.unit_price_cents', 100.5];
        yield 'string price' => ['items.0.unit_price_cents', '100'];
        yield 'excess price' => ['items.0.unit_price_cents', 1000000001];
        yield 'description excess' => ['items.0.description', str_repeat('x', 1001)];
        yield 'control characters' => ['notes', "Texto\0proibido"];
        yield 'notes excess' => ['notes', str_repeat('x', 4001)];
        yield 'invalid UTF-8' => ['notes', "inválido\xff"];
        yield 'scalar items' => ['items', 'invalid'];
        yield 'discount too large' => ['discount', ['type' => 'fixed', 'value' => 35001]];
        yield 'discount negative' => ['discount', ['type' => 'fixed', 'value' => -1]];
        yield 'discount wrong type' => ['discount', ['type' => 'fixed', 'value' => '10']];
        yield 'percent too large' => ['discount', ['type' => 'percent', 'value' => '100.01']];
        yield 'percent too precise' => ['discount', ['type' => 'percent', 'value' => '1.001']];
        yield 'percent numeric' => ['discount', ['type' => 'percent', 'value' => 10]];
        yield 'empty items' => ['items', []];
        yield 'excess items' => ['items', array_fill(0, 101, ['description' => 'Teste', 'quantity' => '1', 'unit_price_cents' => 1])];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_and_arbitrary_input_without_echoing_it(string $key, mixed $value): void
    {
        $input = QuotationDemoFixtures::simple();
        data_set($input, $key, $value);
        $this->expectException(QuotationValidationException::class);
        $this->expectExceptionMessage('Dados do orçamento inválidos.');
        Quotation::fromArray($input);
    }

    public function test_subtotal_cannot_exceed_the_ceiling_even_when_each_line_is_valid(): void
    {
        $input = QuotationDemoFixtures::simple();
        $input['items'] = array_fill(0, 2, ['description' => 'Valor elevado', 'quantity' => '100', 'unit_price_cents' => 1000000000]);
        $this->expectException(QuotationValidationException::class);
        Quotation::fromArray($input);
    }
}
