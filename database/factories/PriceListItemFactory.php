<?php

namespace Database\Factories;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceListItem> */
class PriceListItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::query()->withoutGlobalScopes()->first()?->id
                ?? Tenant::create(['slug' => fake()->unique()->slug(), 'name' => fake()->company()])->id,
            'price_list_id' => fn (array $attributes) => PriceList::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'product_variant_id' => fn (array $attributes) => ProductVariant::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
            ])->id,
            'amount' => 10000,
            'currency' => 'IQD',
        ];
    }
}
