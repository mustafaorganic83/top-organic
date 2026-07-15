<?php

declare(strict_types=1);

namespace Tests\Unit\Sales;

use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Services\SalesCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalesCalculatorTest extends TestCase
{
    public function test_iqd_golden_vector_with_modifiers_discounts_charges_taxes_tip_and_rounding(): void
    {
        $result = (new SalesCalculator)->calculate([
            'currency' => 'IQD',
            'lines' => [[
                'quantity' => '2', 'unit_price_amount' => 1250,
                'modifiers' => [['quantity' => '1', 'unit_surcharge_amount' => 100]],
                'discounts' => [['type' => 'fixed', 'value_amount' => 200]],
                'tax_class_code' => 'VAT5', 'tax_rate_bps' => 500,
            ]],
            'discounts' => [['type' => 'percent', 'rate_bps' => 1000]],
            'charges' => [[
                'code' => 'SERVICE', 'calculation' => 'percent', 'rate_bps' => 1000,
                'tax_class_code' => 'VAT10', 'tax_rate_bps' => 1000,
            ]],
            'tip_amount' => 100,
            'rounding_increment' => 5,
        ]);

        $this->assertSame(2700, $result->subtotalAmount);
        $this->assertSame(450, $result->discountAmount);
        $this->assertSame(225, $result->chargeAmount);
        $this->assertSame(136, $result->taxAmount);
        $this->assertSame(-1, $result->roundingAmount);
        $this->assertSame(2710, $result->totalAmount);
    }

    public function test_usd_tax_inclusive_vector_extracts_tax_without_adding_it_twice(): void
    {
        $result = (new SalesCalculator)->calculate([
            'currency' => 'USD',
            'lines' => [[
                'quantity' => '1', 'unit_price_amount' => 1099,
                'tax_class_code' => 'VAT10', 'tax_rate_bps' => 1000, 'tax_inclusive' => true,
            ]],
        ]);

        $this->assertSame(100, $result->taxAmount);
        $this->assertSame(999, $result->lines[0]['taxable_amount']);
        $this->assertSame(1099, $result->totalAmount);
    }

    public function test_quantity_and_ratio_round_half_up_using_integer_arithmetic(): void
    {
        $result = (new SalesCalculator)->calculate([
            'currency' => 'USD',
            'lines' => [['quantity' => '0.5', 'unit_price_amount' => 1]],
        ]);

        $this->assertSame(1, $result->subtotalAmount);
        $this->assertSame('0.5', $result->lines[0]['quantity']);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_money_quantity_currency_and_overflow_are_rejected(array $input, string $code): void
    {
        try {
            (new SalesCalculator)->calculate($input);
            $this->fail('Expected a sales calculation exception.');
        } catch (SalesException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'currency' => [['currency' => 'usd', 'lines' => []], SalesException::INVALID_MONEY];
        yield 'float money' => [[
            'currency' => 'USD', 'lines' => [['quantity' => '1', 'unit_price_amount' => 1.5]],
        ], SalesException::INVALID_MONEY];
        yield 'negative money' => [[
            'currency' => 'USD', 'lines' => [['quantity' => '1', 'unit_price_amount' => -1]],
        ], SalesException::INVALID_MONEY];
        yield 'noncanonical quantity' => [[
            'currency' => 'USD', 'lines' => [['quantity' => '1.0000000', 'unit_price_amount' => 1]],
        ], SalesException::INVALID_QUANTITY];
        yield 'overflow' => [[
            'currency' => 'USD',
            'lines' => [
                ['quantity' => '1', 'unit_price_amount' => PHP_INT_MAX],
                ['quantity' => '1', 'unit_price_amount' => 1],
            ],
        ], SalesException::ARITHMETIC_OVERFLOW];
    }
}
