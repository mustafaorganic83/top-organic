<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

final readonly class CalculationInput
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $discounts
     * @param  array<int, array<string, mixed>>  $charges
     */
    public function __construct(
        public string $currency,
        public array $lines,
        public array $discounts = [],
        public array $charges = [],
        public int $tipAmount = 0,
        public int $roundingIncrement = 1,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(
            (string) ($input['currency'] ?? ''),
            (array) ($input['lines'] ?? []),
            (array) ($input['discounts'] ?? []),
            (array) ($input['charges'] ?? []),
            $input['tip_amount'] ?? 0,
            $input['rounding_increment'] ?? 1,
        );
    }
}
