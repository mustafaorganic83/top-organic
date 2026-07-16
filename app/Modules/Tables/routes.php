<?php

/*
 | Reservation & Table Management module API routes (versioned under /api/v1).
 | Floor/room/table design, live table occupancy, reservations across every
 | channel (walk-in, phone, call centre, online, WhatsApp, AI), the waiting
 | list, and the tablet reception overview. Scope/permissions mirror Sales.
 */

use App\Modules\Tables\Http\Controllers\FloorController;
use App\Modules\Tables\Http\Controllers\ReceptionController;
use App\Modules\Tables\Http\Controllers\ReservationController;
use App\Modules\Tables\Http\Controllers\RoomController;
use App\Modules\Tables\Http\Controllers\TableController;
use App\Modules\Tables\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api', 'identity.context'])->prefix('api/v1')->name('tables.')->group(function (): void {
    // Floor & room design (layout designer).
    Route::prefix('tables/floors')->group(function (): void {
        Route::get('/', [FloorController::class, 'index'])->middleware('permission:tables.view');
        Route::post('/', [FloorController::class, 'store'])->name('floors.store')->middleware('permission:tables.design');
        Route::patch('{floor}', [FloorController::class, 'update'])->whereUlid('floor')->name('floors.update')->middleware('permission:tables.design');
    });
    Route::post('tables/rooms', [RoomController::class, 'store'])->name('rooms.store')->middleware('permission:tables.design');

    // Dining tables & live occupancy.
    Route::prefix('tables/tables')->group(function (): void {
        Route::get('/', [TableController::class, 'index'])->middleware('permission:tables.view');
        Route::post('/', [TableController::class, 'store'])->name('tables.store')->middleware('permission:tables.design');
        Route::patch('{table}', [TableController::class, 'update'])->whereUlid('table')->name('tables.update')->middleware('permission:tables.design');
        Route::post('{table}/occupancy', [TableController::class, 'occupancy'])->whereUlid('table')->name('tables.occupancy')->middleware('permission:tables.manage');
    });

    // Tablet reception overview (live floor map + reservations + waitlist).
    Route::get('tables/reception/overview', [ReceptionController::class, 'overview'])->middleware('permission:tables.view');

    // Reservations across every channel.
    Route::prefix('reservations')->group(function (): void {
        Route::get('/', [ReservationController::class, 'index'])->middleware('permission:reservations.view');
        Route::post('/', [ReservationController::class, 'store'])->name('reservations.store')->middleware('permission:reservations.create');
        Route::get('{reservation}', [ReservationController::class, 'show'])->whereUlid('reservation')->middleware('permission:reservations.view');
        Route::post('{reservation}/confirm', [ReservationController::class, 'confirm'])->whereUlid('reservation')->name('reservations.confirm')->middleware('permission:reservations.manage');
        Route::post('{reservation}/assign-table', [ReservationController::class, 'assign'])->whereUlid('reservation')->name('reservations.assign')->middleware('permission:reservations.assign');
        Route::post('{reservation}/seat', [ReservationController::class, 'seat'])->whereUlid('reservation')->name('reservations.seat')->middleware(['permission:reservations.seat', 'pos.device']);
        Route::post('{reservation}/complete', [ReservationController::class, 'complete'])->whereUlid('reservation')->name('reservations.complete')->middleware('permission:reservations.manage');
        Route::post('{reservation}/cancel', [ReservationController::class, 'cancel'])->whereUlid('reservation')->name('reservations.cancel')->middleware('permission:reservations.manage');
        Route::post('{reservation}/no-show', [ReservationController::class, 'noShow'])->whereUlid('reservation')->name('reservations.no_show')->middleware('permission:reservations.manage');
    });
    Route::get('reservations/customers/{customer}/history', [ReservationController::class, 'customerHistory'])->whereUlid('customer')->middleware('permission:reservations.view');

    // Waiting list.
    Route::prefix('reservations/waitlist')->group(function (): void {
        Route::get('/', [WaitlistController::class, 'index'])->middleware('permission:reservations.view');
        Route::post('/', [WaitlistController::class, 'store'])->name('waitlist.store')->middleware('permission:reservations.manage');
        Route::post('{entry}/notify', [WaitlistController::class, 'notify'])->whereUlid('entry')->name('waitlist.notify')->middleware('permission:reservations.manage');
        Route::post('{entry}/seat', [WaitlistController::class, 'seat'])->whereUlid('entry')->name('waitlist.seat')->middleware('permission:reservations.manage');
        Route::post('{entry}/cancel', [WaitlistController::class, 'cancel'])->whereUlid('entry')->name('waitlist.cancel')->middleware('permission:reservations.manage');
    });
});
