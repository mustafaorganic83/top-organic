<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->currencyCode());
        return [
            'code' => substr($code, 0, 8),
            'name' => $code.' Currency',
            'symbol' => '$',
            'decimals' => 2,
        ];
    }
}
