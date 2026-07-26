<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ExecutiveDashboardController;
use App\Http\Controllers\Api\RecipeApiController;
use App\Http\Controllers\Api\PreparedItemApiController;
use App\Http\Controllers\Api\InventoryApiController;
use App\Http\Controllers\Api\CostingApiController;
use App\Http\Controllers\Api\SnapshotsApiController;
use App\Http\Controllers\Api\ProductionApiController;
use App\Http\Controllers\Api\PermissionsApiController;

// Reports API
Route::get('/reports', [ReportController::class, 'index']);
Route::get('/reports/{key}', [ReportController::class, 'run']); // ?format=json|csv|excel|pdf&group_by=...&filters...
Route::get('/reports/{key}/drilldown', [ReportController::class, 'drilldown']); // ?group_field=...&group_value=...

// Executive Dashboard API
Route::get('/dashboard/summary', [ExecutiveDashboardController::class, 'summary']);
Route::get('/dashboard/top-ingredients', [ExecutiveDashboardController::class, 'topIngredients']);
Route::get('/dashboard/top-recipes', [ExecutiveDashboardController::class, 'topRecipes']);
Route::get('/dashboard/trend', [ExecutiveDashboardController::class, 'trend']);

// Versioned REST API (JWT bearer, guard: api)
Route::prefix('v1')->middleware('auth:api')->group(function () {
    // Mirror report endpoints under v1
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/{key}', [ReportController::class, 'run']);
    Route::get('/reports/{key}/drilldown', [ReportController::class, 'drilldown']);

    // Recipes
    Route::get('/recipes', [RecipeApiController::class, 'index']);
    Route::post('/recipes', [RecipeApiController::class, 'store']);
    Route::get('/recipes/{recipe}', [RecipeApiController::class, 'show']);
    Route::patch('/recipes/{recipe}', [RecipeApiController::class, 'update']);
    Route::delete('/recipes/{recipe}', [RecipeApiController::class, 'destroy']);
    Route::get('/recipes/{recipe}/versions', [RecipeApiController::class, 'versions']);
    Route::post('/recipes/{recipe}/versions', [RecipeApiController::class, 'createVersion']);
    Route::post('/recipes/versions/{version}/publish', [RecipeApiController::class, 'publishVersion']);
    Route::post('/recipes/versions/{version}/activate', [RecipeApiController::class, 'activateVersion']);

    // Prepared (Semi-finished) items
    Route::get('/prepared-items', [PreparedItemApiController::class, 'index']);
    Route::post('/prepared-items', [PreparedItemApiController::class, 'store']);
    Route::get('/prepared-items/{prepared}', [PreparedItemApiController::class, 'show']);
    Route::patch('/prepared-items/{prepared}', [PreparedItemApiController::class, 'update']);
    Route::delete('/prepared-items/{prepared}', [PreparedItemApiController::class, 'destroy']);
    Route::get('/prepared-items/{prepared}/recipe', [PreparedItemApiController::class, 'recipe']);

    // Inventory
    Route::get('/inventory/stock-levels', [InventoryApiController::class, 'stockLevels']);
    Route::get('/inventory/movements', [InventoryApiController::class, 'movements']);
    Route::post('/inventory/movements', [InventoryApiController::class, 'createMovement']);

    // Costing
    Route::get('/costing/ingredient/{stockItem}', [CostingApiController::class, 'ingredient']);
    Route::get('/costing/recipes/{version}', [CostingApiController::class, 'recipeVersion']);
    Route::get('/costing/recipes/{recipe}/active', [CostingApiController::class, 'recipeActive']);
    Route::post('/costing/snapshots', [CostingApiController::class, 'createSnapshot']);

    // Snapshots
    Route::get('/snapshots', [SnapshotsApiController::class, 'index']);
    Route::get('/snapshots/{snapshot}', [SnapshotsApiController::class, 'show']);

    // Production
    Route::get('/production/orders', [ProductionApiController::class, 'index']);
    Route::post('/production/orders', [ProductionApiController::class, 'store']);
    Route::get('/production/orders/{order}', [ProductionApiController::class, 'show']);
    Route::patch('/production/orders/{order}', [ProductionApiController::class, 'update']);
    Route::post('/production/orders/{order}/start', [ProductionApiController::class, 'start']);
    Route::post('/production/orders/{order}/complete', [ProductionApiController::class, 'complete']);

    // Permissions
    Route::get('/permissions', [PermissionsApiController::class, 'index']);
});
