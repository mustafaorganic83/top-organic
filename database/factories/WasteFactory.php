<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\ProductionOrder;
use App\Models\StockItem;
use App\Models\Waste;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WasteFactory extends Factory
{
    protected $model = Waste::class;

    public function definition(): array
    {
        return [
            'waste_type' => fake()->randomElement(['PRIMARY','DEPARTMENT','OUTPUT','OTHER']),
            'production_order_id' => ProductionOrder::factory(),
            'item_id' => StockItem::factory(),
            'qty' => fake()->randomFloat(3, 0.01, 50),
            'uom_id' => null,
            'pct' => fake()->randomFloat(4, 0, 0.2),
            'department_id' => Department::factory(),
            'warehouse_id' => Warehouse::factory(),
            'reason' => fake()->sentence(6),
            'occurred_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
