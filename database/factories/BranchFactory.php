<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'code' => fake()->unique()->bothify('BR-####'),
            'name' => fake()->company().' Branch',
            'is_active' => true,
        ];
    }
}
