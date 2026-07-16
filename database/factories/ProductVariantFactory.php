<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'code' => fake()->unique()->bothify('VAR-####'),
            'name' => 'Regular',
            'barcode' => fake()->unique()->ean13(),
            'status' => 'active',
        ];
    }
}
