<?php

namespace App\Modules\Orders;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Orders module (architecture doc 01 FR-3). Skeleton only: registers the
 * module's versioned API route group. Order lifecycle, KDS routing, and the
 * offline outbox integration are added in later steps.
 */
class OrdersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1/orders')
            ->name('orders.')
            ->group(__DIR__.'/routes.php');
    }
}
