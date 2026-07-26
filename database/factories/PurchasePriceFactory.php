<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\PurchasePrice;
use App\Models\StockItem;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchasePriceFactory extends Factory
{
    protected $model = PurchasePrice::class;

    public function definition(): array
    {
        $from = fake()->dateTimeBetween('-1 year', 'now');
        return [
            'item_id' => StockItem::factory(),
            'supplier_id' => Supplier::factory(),
            'uom_id' => null,
            'price' => fake()->randomFloat(3, 0.1, 1000),
            'currency_id' => Currency::factory(),
            'effective_from' => $from,
            'effective_to' => null,
            'source' => 'MANUAL',
        ];
    }
}
