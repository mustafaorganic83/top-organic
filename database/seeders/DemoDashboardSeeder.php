<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\Tenant;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoDashboardSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first() ?? Tenant::create(['name' => 'Top Organic', 'slug' => 'top-organic']);
        $branch = Branch::first() ?? Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main Branch', 'code' => 'B001']);

        \App\Models\Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2]
        );

        $warehouse = Warehouse::first() ?? Warehouse::create([
            'id' => Str::ulid(),
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Main Warehouse',
            'code' => 'WH001',
            'status' => 'active'
        ]);

        // 1. Create Stock Items
        $stockItems = [];
        $ingredients = ['Tomato', 'Cucumber', 'Olive Oil', 'Lettuce', 'Chicken', 'Rice', 'Spices', 'Flour', 'Sugar', 'Milk'];
        foreach ($ingredients as $name) {
            $si = StockItem::create([
                'id' => Str::ulid(),
                'tenant_id' => $tenant->id,
                'name' => $name,
                'sku' => strtoupper(substr($name, 0, 3)) . rand(100, 999),
                'kind' => 'ingredient',
                'stock_unit' => 'kg',
                'unit_cost_amount' => rand(500, 5000), // 5.00 - 50.00
                'currency' => 'USD',
                'status' => 'active'
            ]);
            $stockItems[] = $si;

            StockLevel::create([
                'id' => Str::ulid(),
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'stockable_type' => StockItem::class,
                'stockable_id' => $si->id,
                'quantity_on_hand' => rand(50, 200),
                'average_cost_amount' => $si->unit_cost_amount,
            ]);
        }

        // 2. Create Recipes and Product Variants
        $products = ['Salad', 'Grilled Chicken', 'Fried Rice', 'Pasta', 'Cake'];
        $variants = [];
        foreach ($products as $name) {
            $p = Product::create([
                'id' => Str::ulid(),
                'tenant_id' => $tenant->id,
                'name' => $name,
                'sku' => 'P-' . strtoupper(substr($name, 0, 3)) . rand(100, 999),
                'status' => 'active'
            ]);
            $pv = ProductVariant::create([
                'id' => Str::ulid(),
                'tenant_id' => $tenant->id,
                'product_id' => $p->id,
                'name' => 'Standard',
                'code' => 'STD-' . strtoupper(substr($name, 0, 3)),
                'status' => 'active'
            ]);
            $variants[] = $pv;

            $recipe = Recipe::create([
                'id' => Str::ulid(),
                'tenant_id' => $tenant->id,
                'owner_type' => ProductVariant::class,
                'owner_id' => $pv->id,
                'name' => $name . ' Recipe',
            ]);

            $version = RecipeVersion::create([
                'id' => Str::ulid(),
                'tenant_id' => $tenant->id,
                'recipe_id' => $recipe->id,
                'revision' => 1,
                'state' => 'active',
                'recipe_cost_amount' => rand(2000, 8000),
                'currency' => 'USD',
                'activated_at' => now(),
            ]);

            $recipe->update(['active_version_id' => $version->id]);
        }

        // 3. Create History for 30 days
        $now = CarbonImmutable::now();
        for ($i = 0; $i < 30; $i++) {
            $date = $now->subDays($i);
            
            // Purchase movements
            foreach ($stockItems as $si) {
                if (rand(0, 10) > 7) {
                    InventoryMovement::create([
                        'id' => Str::ulid(),
                        'tenant_id' => $tenant->id,
                        'branch_id' => $branch->id,
                        'warehouse_id' => $warehouse->id,
                        'stockable_type' => StockItem::class,
                        'stockable_id' => $si->id,
                        'reason' => 'purchase',
                        'quantity_delta' => rand(10, 50),
                        'unit' => 'kg',
                        'unit_cost_amount' => $si->unit_cost_amount,
                        'occurred_at' => $date->setHour(rand(8, 12)),
                    ]);
                }
            }

            // Waste movements
            foreach ($stockItems as $si) {
                if (rand(0, 10) > 8) {
                    InventoryMovement::create([
                        'id' => Str::ulid(),
                        'tenant_id' => $tenant->id,
                        'branch_id' => $branch->id,
                        'warehouse_id' => $warehouse->id,
                        'stockable_type' => StockItem::class,
                        'stockable_id' => $si->id,
                        'reason' => 'waste',
                        'quantity_delta' => -rand(1, 5),
                        'unit' => 'kg',
                        'unit_cost_amount' => $si->unit_cost_amount,
                        'occurred_at' => $date->setHour(rand(14, 18)),
                    ]);
                }
            }

            // Orders
            $orderCount = rand(5, 15);
            for ($j = 0; $j < $orderCount; $j++) {
                $order = Order::create([
                    'id' => Str::ulid(),
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branch->id,
                    'number' => 'ORD-' . $date->format('Ymd') . '-' . Str::random(4),
                    'type' => 'dine_in',
                    'state' => 'closed',
                    'currency' => 'USD',
                    'business_date' => $date->toDateString(),
                    'placed_at' => $date->setHour(rand(12, 22)),
                    'client_operation_id' => Str::uuid(),
                ]);

                $itemsCount = rand(1, 4);
                $total = 0;
                for ($k = 0; $k < $itemsCount; $k++) {
                    $pv = $variants[array_rand($variants)];
                    $qty = rand(1, 2);
                    $price = rand(1000, 3000); // 10.00 - 30.00
                    $net = $qty * $price;
                    $total += $net;

                    OrderItem::create([
                        'id' => Str::ulid(),
                        'tenant_id' => $tenant->id,
                        'branch_id' => $branch->id,
                        'order_id' => $order->id,
                        'product_id' => $pv->product_id,
                        'product_variant_id' => $pv->id,
                        'line_number' => $k + 1,
                        'product_name' => $pv->product->name,
                        'quantity' => $qty,
                        'unit_price_amount' => $price,
                        'gross_amount' => $net,
                        'net_amount' => $net,
                        'currency' => 'USD',
                        'created_at' => $order->placed_at,
                    ]);
                }
                $order->update(['total_amount' => $total]);
            }
        }
    }
}
