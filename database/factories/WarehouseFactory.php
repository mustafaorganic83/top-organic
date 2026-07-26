<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => fake()->unique()->bothify('WH-####'),
            'name' => fake()->word().' warehouse',
            'is_default' => false,
            'is_sellable_source' => false,
        ];
    }
}
