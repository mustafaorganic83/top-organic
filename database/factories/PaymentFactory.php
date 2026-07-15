<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'branch_id' => fn (array $attributes) => Branch::query()->withoutGlobalScopes()
                ->where('tenant_id', $attributes['tenant_id'])->first()?->id
                ?? Branch::create(['tenant_id' => $attributes['tenant_id'], 'code' => fake()->unique()->bothify('B-###'), 'name' => fake()->city()])->id,
            'payment_method_id' => fn (array $attributes) => PaymentMethod::create([
                'tenant_id' => $attributes['tenant_id'], 'code' => fake()->unique()->bothify('PAY-###'),
                'name' => 'Cash', 'kind' => 'cash',
            ])->id,
            'status' => 'captured',
            'tender_amount' => 10000,
            'tender_currency' => 'IQD',
            'base_amount' => 10000,
            'base_currency' => 'IQD',
            'idempotency_key' => (string) Str::ulid(),
            'client_operation_id' => (string) Str::ulid(),
            'captured_at' => now(),
            'occurred_at' => now(),
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn () => [
            'tenant_id' => $order->tenant_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
        ]);
    }
}
