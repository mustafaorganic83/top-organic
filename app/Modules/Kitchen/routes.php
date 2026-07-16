<?php

use App\Modules\Kitchen\Http\Controllers\AnalyticsController;
use App\Modules\Kitchen\Http\Controllers\QueueController;
use App\Modules\Kitchen\Http\Controllers\StationController;
use App\Modules\Kitchen\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

/**
 * Kitchen Management API (architecture doc 01 FR-5). All routes carry the
 * trusted identity context and per-endpoint permissions. The board reuses the
 * Sales KDS aggregate; POS dispatch to the kitchen remains under /sales/kds.
 */
Route::middleware(['api', 'auth:api', 'identity.context'])->prefix('api/v1/kitchen')->name('kitchen.')->group(function (): void {
    // Station & screen configuration.
    Route::prefix('stations')->group(function (): void {
        Route::get('/', [StationController::class, 'index'])->middleware('permission:kitchen.view');
        Route::post('/', [StationController::class, 'store'])->name('stations.store')->middleware('permission:kitchen.manage');
        Route::get('{station}', [StationController::class, 'show'])->whereUlid('station')->middleware('permission:kitchen.view');
        Route::patch('{station}', [StationController::class, 'update'])->whereUlid('station')->name('stations.update')->middleware('permission:kitchen.manage');
    });

    // The kitchen board: full phase board and single-phase queues.
    Route::get('board', [QueueController::class, 'board'])->middleware('permission:kitchen.view');
    Route::get('queues/{phase}', [QueueController::class, 'phase'])
        ->whereIn('phase', ['preparation', 'cooking', 'ready', 'served'])->middleware('permission:kitchen.view');

    // Tickets: read + lifecycle + assignment + priority.
    Route::prefix('tickets')->group(function (): void {
        Route::get('{ticket}', [QueueController::class, 'show'])->whereUlid('ticket')->middleware('permission:kitchen.view');
        Route::post('{ticket}/start', [TicketController::class, 'start'])->whereUlid('ticket')->name('tickets.start')->middleware('permission:kitchen.operate');
        Route::post('{ticket}/ready', [TicketController::class, 'ready'])->whereUlid('ticket')->name('tickets.ready')->middleware('permission:kitchen.operate');
        Route::post('{ticket}/serve', [TicketController::class, 'serve'])->whereUlid('ticket')->name('tickets.serve')->middleware('permission:kitchen.operate');
        Route::post('{ticket}/assign', [TicketController::class, 'assign'])->whereUlid('ticket')->name('tickets.assign')->middleware('permission:kitchen.assign');
        Route::post('{ticket}/priority', [TicketController::class, 'priority'])->whereUlid('ticket')->name('tickets.priority')->middleware('permission:kitchen.manage');
    });

    // Analytics & KPIs.
    Route::prefix('analytics')->group(function (): void {
        Route::get('kpis', [AnalyticsController::class, 'kpis'])->middleware('permission:kitchen.analytics');
        Route::get('chefs', [AnalyticsController::class, 'chefPerformance'])->middleware('permission:kitchen.analytics');
    });
});
