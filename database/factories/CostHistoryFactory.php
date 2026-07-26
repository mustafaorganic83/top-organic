<?php

namespace Database\Factories;

use App\Models\CostHistory;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class CostHistoryFactory extends Factory
{
    protected $model = CostHistory::class;

    public function definition(): array
    {
        $from = fake()->dateTimeBetween('-6 months', '-1 month');
        return [
            'entity_type' => fake()->randomElement(['ITEM','PREPARED','RECIPE']),
            'entity_id' => fake()->numberBetween(1, 1000),
            'method' => fake()->randomElement(['LAST','MOVING_AVG','FIFO','STANDARD']),
            'unit_cost' => fake()->randomFloat(3, 0.05, 400),
            'currency_id' => Currency::factory(),
            'effective_from' => $from,
            'effective_to' => null,
        ];
    }
}
