<?php

namespace Database\Factories;

use App\Models\PriceList;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceList> */
class PriceListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'code' => fake()->unique()->bothify('PRICE-####'),
            'name' => 'Default price list',
            'currency' => 'IQD',
            'channel' => 'all',
            'revision' => 1,
            'status' => 'published',
            'effective_from' => now(),
        ];
    }
}
