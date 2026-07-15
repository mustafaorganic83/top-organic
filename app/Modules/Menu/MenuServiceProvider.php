<?php

namespace App\Modules\Menu;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Menu & Catalog module (architecture doc 01 FR-2). Skeleton only: registers
 * the module's versioned API route group. Business features (categories,
 * products, variants, modifiers, combos, recipes) are added in later steps.
 */
class MenuServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1/menu')
            ->name('menu.')
            ->group(__DIR__.'/routes.php');
    }
}
