<?php

namespace App\Modules\Procurement;

use Illuminate\Support\ServiceProvider;

/**
 * Procurement module (architecture doc 01 FR-3). Registers versioned API
 * routes for suppliers, supplier evaluations, RFQs, quotation comparison,
 * purchase requests, purchase orders, goods receipts, inspections, vendor
 * contracts, and payment schedules.
 */
class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
