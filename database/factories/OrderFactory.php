<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'branch_id' => fn (array $attributes) => Branch::query()->withoutGlobalScopes()
                ->where('tenant_id', $attributes['tenant_id'])->first()?->id
                ?? Branch::create(['tenant_id' => $attributes['tenant_id'], 'code' => fake()->unique()->bothify('B-###'), 'name' => fake()->city()])->id,
            'number' => fake()->unique()->bothify('ORD-########'),
            'type' => 'takeaway',
            'source' => 'pos',
            'state' => 'draft',
            'currency' => 'IQD',
            'business_date' => today(),
            'client_operation_id' => (string) Str::ulid(),
        ];
    }
}
