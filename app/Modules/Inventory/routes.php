<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Inventory Management API routes — defined below in full.
// Placeholder: routes are fully implemented in the HTTP layer build step.
Route::prefix('api/v1')->middleware(['api', 'auth:api', 'identity.context'])->group(function (): void {
    // Routes registered in InventoryServiceProvider via loadRoutesFrom.
});
