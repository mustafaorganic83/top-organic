<?php

namespace App\Modules\Reports;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Reports module (architecture doc 01 FR-8). Skeleton only: registers the
 * module's versioned API route group. Cross-branch aggregates and dashboards
 * (served from read replicas) are added in later steps.
 */
class ReportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1/reports')
            ->name('reports.')
            ->group(__DIR__.'/routes.php');
    }
}
