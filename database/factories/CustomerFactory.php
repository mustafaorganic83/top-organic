<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        $phone = fake()->unique()->e164PhoneNumber();
        $email = fake()->unique()->safeEmail();

        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'customer_number' => fake()->unique()->bothify('CUS-######'),
            'name' => fake()->name(),
            'phone' => $phone,
            'phone_hash' => hash('sha256', $phone),
            'email' => $email,
            'email_hash' => hash('sha256', strtolower($email)),
            'status' => 'active',
        ];
    }
}
