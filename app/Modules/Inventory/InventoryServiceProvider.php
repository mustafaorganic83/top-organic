<?php

namespace App\Modules\Inventory;

use Illuminate\Support\ServiceProvider;

/**
 * Inventory Management module (architecture doc 01 FR-2). Registers the
 * module's versioned API routes: warehouses, batch/lot-tracked stock with
 * expiry (FIFO/FEFO), moving-average costing, receipts, adjustments & waste,
 * warehouse transfers, physical & cycle counts, purchase requests, and the
 * inventory audit trail. Route definitions carry their own full paths and
 * middleware, mirroring the Menu and Kitchen providers.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
