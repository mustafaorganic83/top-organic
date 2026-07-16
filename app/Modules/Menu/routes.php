<?php

use App\Modules\Menu\Http\Controllers\IngredientController;
use App\Modules\Menu\Http\Controllers\InventoryController;
use App\Modules\Menu\Http\Controllers\MediaController;
use App\Modules\Menu\Http\Controllers\MenuController;
use App\Modules\Menu\Http\Controllers\NutritionController;
use App\Modules\Menu\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

/**
 * Menu & Recipe Management API (architecture doc 01 FR-2). All routes carry
 * the trusted identity context and per-endpoint permissions. Menu/recipe
 * definitions are tenant-wide; stock and consumption are branch-scoped.
 */
Route::middleware(['api', 'auth:api', 'identity.context'])->prefix('api/v1/menu')->name('menu.')->group(function (): void {
    // Menu categories.
    Route::prefix('categories')->group(function (): void {
        Route::get('/', [MenuController::class, 'categories'])->middleware('permission:menu.view');
        Route::post('/', [MenuController::class, 'storeCategory'])->name('categories.store')->middleware('permission:menu.manage');
        Route::patch('{category}', [MenuController::class, 'updateCategory'])->whereUlid('category')->name('categories.update')->middleware('permission:menu.manage');
    });

    // Products, their meal-size variants, and modifier attachment.
    Route::prefix('products')->group(function (): void {
        Route::get('/', [MenuController::class, 'products'])->middleware('permission:menu.view');
        Route::post('/', [MenuController::class, 'storeProduct'])->name('products.store')->middleware('permission:menu.manage');
        Route::get('{product}', [MenuController::class, 'showProduct'])->whereUlid('product')->middleware('permission:menu.view');
        Route::patch('{product}', [MenuController::class, 'updateProduct'])->whereUlid('product')->name('products.update')->middleware('permission:menu.manage');
        Route::post('{product}/variants', [MenuController::class, 'storeVariant'])->whereUlid('product')->name('products.variants.store')->middleware('permission:menu.manage');
        Route::patch('{product}/variants/{variant}', [MenuController::class, 'updateVariant'])->whereUlid('product')->whereUlid('variant')->name('products.variants.update')->middleware('permission:menu.manage');
        Route::post('{product}/modifiers', [MenuController::class, 'attachModifierGroup'])->whereUlid('product')->name('products.modifiers.attach')->middleware('permission:menu.manage');
    });

    // Modifier groups & options (extras).
    Route::prefix('modifier-groups')->group(function (): void {
        Route::get('/', [MenuController::class, 'modifierGroups'])->middleware('permission:menu.view');
        Route::post('/', [MenuController::class, 'storeModifierGroup'])->name('modifier-groups.store')->middleware('permission:menu.manage');
        Route::post('{group}/options', [MenuController::class, 'storeModifierOption'])->whereUlid('group')->name('modifier-groups.options.store')->middleware('permission:menu.manage');
    });

    // Media: images & videos for any catalog entity.
    Route::prefix('media')->group(function (): void {
        Route::get('/', [MediaController::class, 'index'])->middleware('permission:menu.view');
        Route::post('/', [MediaController::class, 'store'])->name('media.store')->middleware('permission:menu.manage');
        Route::patch('{media}', [MediaController::class, 'update'])->whereUlid('media')->name('media.update')->middleware('permission:menu.manage');
        Route::delete('{media}', [MediaController::class, 'destroy'])->whereUlid('media')->name('media.destroy')->middleware('permission:menu.manage');
    });

    // Ingredients (stock items) & semi-finished products.
    Route::prefix('ingredients')->group(function (): void {
        Route::get('/', [IngredientController::class, 'index'])->middleware('permission:menu.view');
        Route::post('/', [IngredientController::class, 'store'])->name('ingredients.store')->middleware('permission:recipe.manage');
        Route::patch('{ingredient}', [IngredientController::class, 'update'])->whereUlid('ingredient')->name('ingredients.update')->middleware('permission:recipe.manage');
    });
    Route::prefix('semi-finished')->group(function (): void {
        Route::get('/', [IngredientController::class, 'semiFinished'])->middleware('permission:menu.view');
        Route::post('/', [IngredientController::class, 'storeSemiFinished'])->name('semi-finished.store')->middleware('permission:recipe.manage');
    });

    // Allergen catalog.
    Route::prefix('allergens')->group(function (): void {
        Route::get('/', [NutritionController::class, 'allergens'])->middleware('permission:menu.view');
        Route::post('/', [NutritionController::class, 'storeAllergen'])->name('allergens.store')->middleware('permission:menu.manage');
        Route::post('tag', [NutritionController::class, 'tag'])->name('allergens.tag')->middleware('permission:menu.manage');
    });
    // Nutrition & allergen roll-up for a product.
    Route::get('products/{product}/nutrition', [NutritionController::class, 'productNutrition'])->whereUlid('product')->middleware('permission:menu.view');
});

/**
 * Recipe builder, costing, and inventory. Grouped under /api/v1/recipes so the
 * kitchen/back-office recipe workflow reads cleanly, while sharing the same
 * trusted context and Menu permissions.
 */
Route::middleware(['api', 'auth:api', 'identity.context'])->prefix('api/v1/recipes')->name('recipes.')->group(function (): void {
    Route::get('/', [RecipeController::class, 'index'])->middleware('permission:recipe.view');
    Route::post('/', [RecipeController::class, 'store'])->name('store')->middleware('permission:recipe.manage');
    Route::get('{recipe}', [RecipeController::class, 'show'])->whereUlid('recipe')->middleware('permission:recipe.view');

    // Versions: draft the BOM, publish (freezes cost + nutrition), activate.
    Route::post('{recipe}/versions', [RecipeController::class, 'draftVersion'])->whereUlid('recipe')->name('versions.draft')->middleware('permission:recipe.manage');
    Route::post('{recipe}/versions/{version}/publish', [RecipeController::class, 'publishVersion'])->whereUlid('recipe')->whereUlid('version')->name('versions.publish')->middleware('permission:recipe.publish');
    Route::post('{recipe}/versions/{version}/activate', [RecipeController::class, 'activateVersion'])->whereUlid('recipe')->whereUlid('version')->name('versions.activate')->middleware('permission:recipe.publish');

    // Costing read-outs: recipe cost, food cost %, yield, waste.
    Route::get('{recipe}/cost', [RecipeController::class, 'cost'])->whereUlid('recipe')->name('cost')->middleware('permission:recipe.view');
});

Route::middleware(['api', 'auth:api', 'identity.context'])->prefix('api/v1/inventory')->name('inventory.')->group(function (): void {
    Route::get('levels', [InventoryController::class, 'levels'])->middleware('permission:inventory.view');
    Route::get('movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view');
    Route::post('adjust', [InventoryController::class, 'adjust'])->name('adjust')->middleware('permission:inventory.manage');
    Route::post('consume', [InventoryController::class, 'consume'])->name('consume')->middleware('permission:inventory.consume');
});
