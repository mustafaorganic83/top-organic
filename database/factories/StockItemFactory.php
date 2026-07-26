<?php

namespace Database\Factories;

use App\Models\StockItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockItem> */
class StockItemFactory extends Factory
{
    protected $model = StockItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'name' => fake()->unique()->words(2, true),
            'is_perishable' => fake()->boolean(),
            'is_batch_tracked' => fake()->boolean(),
            'unit_cost_amount' => fake()->numberBetween(100, 100000),
            'default_waste_bps' => fake()->numberBetween(0, 2000),
        ];
    }
}
