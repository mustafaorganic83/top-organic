<?php

namespace App\Modules\Staff;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Staff module (architecture doc 01 FR-1 / FR-7). Skeleton only: registers
 * the module's versioned API route group. Staff management, shifts, and
 * attendance are added in later steps.
 */
class StaffServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1/staff')
            ->name('staff.')
            ->group(__DIR__.'/routes.php');
    }
}
