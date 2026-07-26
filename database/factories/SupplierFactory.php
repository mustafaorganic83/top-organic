<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'code' => fake()->unique()->bothify('SUP-####'),
            'name' => fake()->company(),
        ];
    }
}
