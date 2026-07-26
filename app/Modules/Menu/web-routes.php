<?php

use App\Modules\Menu\Http\Controllers\CostReportController;
use App\Modules\Menu\Livewire\CategoryManager;
use App\Modules\Menu\Livewire\CostReports;
use App\Modules\Menu\Livewire\DishForm;
use App\Modules\Menu\Livewire\DishManager;
use App\Modules\Menu\Livewire\IngredientManager;
use Illuminate\Support\Facades\Route;

/**
 * Dish-management back office. These are the session-authenticated Livewire
 * screens over the same services the API uses; web.context resolves the
 * tenant/branch scope from the signed-in user, and the components enforce
 * per-action permissions themselves so a Livewire update cannot bypass them.
 */
Route::middleware(['web', 'auth:web', 'web.context'])->group(function (): void {
    Route::get('dishes', DishManager::class)->name('dishes.index');
    Route::get('dishes/create', DishForm::class)->name('dishes.create');
    Route::get('dishes/{product}/edit', DishForm::class)->whereUlid('product')->name('dishes.edit');

    Route::get('dish-categories', CategoryManager::class)->name('dish-categories.index');
    Route::get('ingredients', IngredientManager::class)->name('ingredients.index');

    Route::get('menu-reports', CostReports::class)->name('menu-reports.index');
    Route::get('menu-reports/{kind}/pdf', [CostReportController::class, 'pdf'])
        ->middleware('permission:menu.view')->name('menu-reports.pdf');
    Route::get('menu-reports/{kind}/excel', [CostReportController::class, 'excel'])
        ->middleware('permission:menu.view')->name('menu-reports.excel');
});
