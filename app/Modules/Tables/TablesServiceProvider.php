<?php

namespace App\Modules\Tables;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Tables & Floor module (architecture doc 01 FR-4). Skeleton only: registers
 * the module's versioned API route group. Floor layout, table state, and
 * reservations are added in later steps.
 */
class TablesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1/tables')
            ->name('tables.')
            ->group(__DIR__.'/routes.php');
    }
}
