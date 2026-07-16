<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Data\CalculationInput;
use App\Modules\Sales\Data\CalculationResult;
use App\Modules\Sales\Exceptions\SalesException;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final class SalesCalculator
{
    public const QUANTITY_SCALE = 1_000_000;

    public function calculate(CalculationInput|array $input): CalculationResult
    {
        $input = is_array($input) ? CalculationInput::fromArray($input) : $input;
        $this->assertCurrency($input->currency);
        $this->assertMoney($input->tipAmount, 'tip_amount');
        if ($input->roundingIncrement < 1) {
            throw SalesException::invalid('Rounding increment must be a positive integer.');
        }

        $lines = [];
        $subtotal = 0;
        $lineDiscountTotal = 0;
        foreach ($input->lines as $index => $line) {
            $lines[] = $this->calculateLine($line, $index, $input->currency);
            $subtotal = $this->add($subtotal, $lines[$index]['gross_amount']);
            $lineDiscountTotal = $this->add($lineDiscountTotal, $lines[$index]['line_discount_amount']);
        }

        [$orderDiscount, $discounts] = $this->applyDiscounts(
            $subtotal - $lineDiscountTotal,
            $input->discounts,
            $input->currency,
        );
        $this->allocateOrderDiscount($lines, $orderDiscount);

        $taxes = [];
        $taxAmount = 0;
        $exclusiveTax = 0;
        $merchandiseNet = 0;
        foreach ($lines as &$line) {
            $line['discount_amount'] = $this->add($line['line_discount_amount'], $line['order_discount_amount']);
            $taxableGross = $line['gross_amount'] - $line['discount_amount'];
            [$tax, $taxable] = $this->tax($taxableGross, $line['tax_rate_bps'], $line['tax_inclusive']);
            $line['taxable_amount'] = $taxable;
            $line['tax_amount'] = $tax;
            $line['net_amount'] = $this->add($taxableGross, $line['tax_inclusive'] ? 0 : $tax);
            $merchandiseNet = $this->add($merchandiseNet, $taxableGross);
            $taxAmount = $this->add($taxAmount, $tax);
            if (! $line['tax_inclusive']) {
                $exclusiveTax = $this->add($exclusiveTax, $tax);
            }
            if ($line['tax_rate_bps'] > 0) {
                $taxes[] = $this->taxBreakdown($line, $taxable, $tax);
            }
            unset($line['line_discount_amount'], $line['tax_rate_bps'], $line['tax_inclusive']);
        }
        unset($line);

        [$chargeAmount, $chargeTax, $chargeExclusiveTax, $charges, $chargeTaxes] =
            $this->calculateCharges($merchandiseNet, $input->charges, $input->currency);
        $taxAmount = $this->add($taxAmount, $chargeTax);
        $exclusiveTax = $this->add($exclusiveTax, $chargeExclusiveTax);
        $taxes = [...$taxes, ...$chargeTaxes];

        $unrounded = $this->add($merchandiseNet, $chargeAmount);
        $unrounded = $this->add($unrounded, $exclusiveTax);
        $unrounded = $this->add($unrounded, $input->tipAmount);
        $total = $this->roundToIncrement($unrounded, $input->roundingIncrement);

        return new CalculationResult(
            $input->currency,
            $subtotal,
            $this->add($lineDiscountTotal, $orderDiscount),
            $chargeAmount,
            $taxAmount,
            $input->tipAmount,
            $total - $unrounded,
            $total,
            $lines,
            $discounts,
            $charges,
            $taxes,
        );
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function calculateLine(array $line, int $index, string $currency): array
    {
        $this->assertSameCurrency($currency, $line['currency'] ?? $currency);
        $quantity = $this->quantityToScale($line['quantity'] ?? null);
        $unitPrice = $this->money($line['unit_price_amount'] ?? null, 'unit_price_amount');
        $base = $this->scaleMoney($unitPrice, $quantity);
        $modifierTotal = 0;
        $modifiers = [];
        foreach ((array) ($line['modifiers'] ?? []) as $modifier) {
            $this->assertSameCurrency($currency, $modifier['currency'] ?? $currency);
            $modifierQuantity = $this->quantityToScale($modifier['quantity'] ?? '1');
            $unit = $this->money($modifier['unit_surcharge_amount'] ?? null, 'unit_surcharge_amount');
            $total = $this->scaleMoney($this->scaleMoney($unit, $modifierQuantity), $quantity);
            $modifierTotal = $this->add($modifierTotal, $total);
            $modifiers[] = [...$modifier, 'quantity' => $this->canonicalQuantity($modifierQuantity), 'total_surcharge_amount' => $total];
        }
        $gross = $this->add($base, $modifierTotal);
        [$discount, $discounts] = $this->applyDiscounts($gross, (array) ($line['discounts'] ?? []), $currency);
        $rate = $this->bps($line['tax_rate_bps'] ?? 0, 'tax_rate_bps');

        return [
            'line_id' => (string) ($line['line_id'] ?? $index + 1),
            'quantity' => $this->canonicalQuantity($quantity),
            'unit_price_amount' => $unitPrice,
            'base_amount' => $base,
            'modifier_amount' => $modifierTotal,
            'gross_amount' => $gross,
            'line_discount_amount' => $discount,
            'order_discount_amount' => 0,
            'discounts' => $discounts,
            'modifiers' => $modifiers,
            'tax_class_code' => $line['tax_class_code'] ?? null,
            'tax_rate_bps' => $rate,
            'tax_inclusive' => (bool) ($line['tax_inclusive'] ?? false),
            'currency' => $currency,
        ];
    }

    /** @param array<int, array<string, mixed>> $discounts @return array{int, array<int, array<string, mixed>>} */
    private function applyDiscounts(int $basis, array $discounts, string $currency): array
    {
        $remaining = $basis;
        $applied = [];
        $total = 0;
        foreach ($discounts as $discount) {
            $this->assertSameCurrency($currency, $discount['currency'] ?? $currency);
            $type = $discount['type'] ?? null;
            $amount = match ($type) {
                'fixed' => $this->money($discount['value_amount'] ?? $discount['fixed_amount'] ?? null, 'discount value'),
                'percent' => $this->ratio($remaining, $this->bps($discount['rate_bps'] ?? null, 'discount rate')),
                default => throw SalesException::invalid('Discount type must be fixed or percent.'),
            };
            if (array_key_exists('maximum_discount_amount', $discount) && $discount['maximum_discount_amount'] !== null) {
                $amount = min($amount, $this->money($discount['maximum_discount_amount'], 'maximum_discount_amount'));
            }
            $amount = min($amount, $remaining);
            $remaining -= $amount;
            $total = $this->add($total, $amount);
            $applied[] = [...$discount, 'basis_amount' => $basis, 'applied_amount' => $amount, 'currency' => $currency];
        }

        return [$total, $applied];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function allocateOrderDiscount(array &$lines, int $discount): void
    {
        $basis = 0;
        foreach ($lines as $line) {
            $basis = $this->add($basis, $line['gross_amount'] - $line['line_discount_amount']);
        }
        $remainingDiscount = $discount;
        $remainingBasis = $basis;
        foreach ($lines as $index => &$line) {
            $lineBasis = $line['gross_amount'] - $line['line_discount_amount'];
            $allocation = ($index === array_key_last($lines) || $remainingBasis === 0)
                ? $remainingDiscount
                : min($remainingDiscount, $this->mulDiv($lineBasis, $remainingDiscount, $remainingBasis));
            $line['order_discount_amount'] = min($allocation, $lineBasis);
            $remainingDiscount -= $line['order_discount_amount'];
            $remainingBasis -= $lineBasis;
        }
        unset($line);
    }

    /** @param array<int, array<string, mixed>> $charges @return array{int, int, int, array<int, array<string, mixed>>, array<int, array<string, mixed>>} */
    private function calculateCharges(int $basis, array $charges, string $currency): array
    {
        $total = $taxTotal = $exclusiveTax = 0;
        $breakdown = $taxes = [];
        foreach ($charges as $index => $charge) {
            $this->assertSameCurrency($currency, $charge['currency'] ?? $currency);
            $amount = match ($charge['calculation'] ?? $charge['value_type'] ?? 'fixed') {
                'fixed' => $this->money($charge['fixed_amount'] ?? $charge['amount'] ?? null, 'charge amount'),
                'percent' => $this->ratio($basis, $this->bps($charge['rate_bps'] ?? null, 'charge rate')),
                default => throw SalesException::invalid('Charge calculation must be fixed or percent.'),
            };
            $rate = $this->bps($charge['tax_rate_bps'] ?? 0, 'charge tax rate');
            $inclusive = (bool) ($charge['tax_inclusive'] ?? false);
            [$tax, $taxable] = $this->tax($amount, $rate, $inclusive);
            $total = $this->add($total, $amount);
            $taxTotal = $this->add($taxTotal, $tax);
            if (! $inclusive) {
                $exclusiveTax = $this->add($exclusiveTax, $tax);
            }
            $breakdown[] = [...$charge, 'basis_amount' => $basis, 'amount' => $amount, 'tax_amount' => $tax, 'currency' => $currency];
            if ($rate > 0) {
                $taxes[] = [
                    'source' => 'charge', 'source_id' => (string) ($charge['code'] ?? $index + 1),
                    'tax_class_code' => $charge['tax_class_code'] ?? null, 'taxable_amount' => $taxable,
                    'rate_bps' => $rate, 'tax_amount' => $tax, 'is_inclusive' => $inclusive, 'currency' => $currency,
                ];
            }
        }

        return [$total, $taxTotal, $exclusiveTax, $breakdown, $taxes];
    }

    /** @return array{int, int} */
    private function tax(int $amount, int $rate, bool $inclusive): array
    {
        if ($rate === 0) {
            return [0, $amount];
        }
        $tax = $inclusive ? $this->mulDiv($amount, $rate, 10_000 + $rate) : $this->ratio($amount, $rate);

        return [$tax, $inclusive ? $amount - $tax : $amount];
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function taxBreakdown(array $line, int $taxable, int $tax): array
    {
        return [
            'source' => 'line', 'source_id' => $line['line_id'], 'tax_class_code' => $line['tax_class_code'],
            'taxable_amount' => $taxable, 'rate_bps' => $line['tax_rate_bps'], 'tax_amount' => $tax,
            'is_inclusive' => $line['tax_inclusive'], 'currency' => $line['currency'],
        ];
    }

    public function quantityToScale(mixed $quantity): int
    {
        if (! is_string($quantity) || ! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/', $quantity, $match)) {
            throw new SalesException(SalesException::INVALID_QUANTITY, 422, 'Quantity must be a canonical decimal string with at most six decimal places.');
        }
        try {
            $whole = BigInteger::of($match[1])->multipliedBy(self::QUANTITY_SCALE);
            $fraction = str_pad($match[2] ?? '', 6, '0');
            $scaled = $whole->plus($fraction === '' ? 0 : (int) $fraction)->toInt();
        } catch (MathException) {
            throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'Quantity exceeds the supported range.');
        }
        if ($scaled < 1) {
            throw new SalesException(SalesException::INVALID_QUANTITY, 422, 'Quantity must be greater than zero.');
        }

        return $scaled;
    }

    public function canonicalQuantity(int $scaled): string
    {
        $whole = intdiv($scaled, self::QUANTITY_SCALE);
        $fraction = rtrim(str_pad((string) ($scaled % self::QUANTITY_SCALE), 6, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole.'.'.$fraction;
    }

    private function scaleMoney(int $amount, int $quantity): int
    {
        return $this->mulDiv($amount, $quantity, self::QUANTITY_SCALE);
    }

    private function ratio(int $amount, int $rateBps): int
    {
        return $this->mulDiv($amount, $rateBps, 10_000);
    }

    private function mulDiv(int $left, int $right, int $divisor): int
    {
        try {
            return BigInteger::of($left)->multipliedBy($right)->dividedBy($divisor, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'The monetary calculation exceeded the supported integer range.');
        }
    }

    private function roundToIncrement(int $amount, int $increment): int
    {
        try {
            return BigInteger::of($this->mulDiv($amount, 1, $increment))->multipliedBy($increment)->toInt();
        } catch (MathException) {
            throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'The rounded total exceeded the supported integer range.');
        }
    }

    private function add(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'The monetary total exceeded the supported integer range.');
        }

        return $left + $right;
    }

    private function money(mixed $amount, string $field): int
    {
        if (! is_int($amount) || $amount < 0) {
            throw new SalesException(SalesException::INVALID_MONEY, 422, "{$field} must be a non-negative integer in minor units.");
        }

        return $amount;
    }

    private function assertMoney(mixed $amount, string $field): void
    {
        $this->money($amount, $field);
    }

    private function bps(mixed $rate, string $field): int
    {
        if (! is_int($rate) || $rate < 0 || $rate > 10_000) {
            throw SalesException::invalid("{$field} must be an integer between 0 and 10000 basis points.");
        }

        return $rate;
    }

    private function assertCurrency(string $currency): void
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new SalesException(SalesException::INVALID_MONEY, 422, 'Currency must be an uppercase ISO 4217 code.');
        }
    }

    private function assertSameCurrency(string $expected, mixed $actual): void
    {
        if (! is_string($actual) || $actual !== $expected) {
            throw new SalesException(SalesException::CURRENCY_MISMATCH, 422, 'All calculation components must use the order currency.');
        }
    }
}
