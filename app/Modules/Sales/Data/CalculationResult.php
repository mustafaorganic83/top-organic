<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

final readonly class CalculationResult
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $discounts
     * @param  array<int, array<string, mixed>>  $charges
     * @param  array<int, array<string, mixed>>  $taxes
     */
    public function __construct(
        public string $currency,
        public int $subtotalAmount,
        public int $discountAmount,
        public int $chargeAmount,
        public int $taxAmount,
        public int $tipAmount,
        public int $roundingAmount,
        public int $totalAmount,
        public array $lines,
        public array $discounts,
        public array $charges,
        public array $taxes,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'subtotal_amount' => $this->subtotalAmount,
            'discount_amount' => $this->discountAmount,
            'charge_amount' => $this->chargeAmount,
            'tax_amount' => $this->taxAmount,
            'tip_amount' => $this->tipAmount,
            'rounding_amount' => $this->roundingAmount,
            'total_amount' => $this->totalAmount,
            'lines' => $this->lines,
            'discounts' => $this->discounts,
            'charges' => $this->charges,
            'taxes' => $this->taxes,
        ];
    }
}
