<?php

namespace Database\Factories;

use App\Models\SemiFinishedProduct;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemiFinishedProduct> */
class SemiFinishedProductFactory extends Factory
{
    protected $model = SemiFinishedProduct::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'name' => 'Semi '.fake()->unique()->word(),
            'yield_quantity' => fake()->randomFloat(3, 1, 100),
            'calories_per_unit' => fake()->numberBetween(0, 500),
        ];
    }
}
