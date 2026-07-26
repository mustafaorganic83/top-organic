<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ProductionOrder;
use App\Models\SemiFinishedProduct;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductionOrderFactory extends Factory
{
    protected $model = ProductionOrder::class;

    public function definition(): array
    {
        $planned = fake()->randomFloat(3, 1, 1000);
        return [
            'branch_id' => Branch::factory(),
            'warehouse_id' => Warehouse::factory(),
            'prepared_recipe_id' => SemiFinishedProduct::factory(),
            'planned_qty' => $planned,
            'actual_qty' => null,
            'uom_id' => null,
            'status' => 'PLANNED',
            'scheduled_at' => fake()->dateTimeBetween('now', '+1 month'),
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
