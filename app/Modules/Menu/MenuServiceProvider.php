<?php

namespace App\Modules\Menu;

use Illuminate\Support\ServiceProvider;

/**
 * Menu & Recipe Management module (architecture doc 01 FR-2). Registers the
 * module's versioned API routes: menu categories/products/variants/modifiers
 * and meal sizes (building on the Sales catalog), rich media (images/videos),
 * the recipe builder with versioning, costing (recipe cost / food cost / yield
 * / waste), ingredients & semi-finished stock, nutrition & allergens, and the
 * automatic inventory-consumption ledger. Route definitions carry their own
 * full paths and middleware, mirroring the Kitchen and Tables providers.
 */
class MenuServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
