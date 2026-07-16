<?php

namespace App\Modules\Billing;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Billing & Payments module (architecture doc 01 FR-5). Skeleton only:
 * registers the module's versioned API route group. Multi-tender payments,
 * dual-currency invoicing, and Iraqi tax policy are added in later steps.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1/billing')
            ->name('billing.')
            ->group(__DIR__.'/routes.php');
    }
}
