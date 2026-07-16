<?php

use App\Modules\Sales\Http\Controllers\BillingController;
use App\Modules\Sales\Http\Controllers\CatalogController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\GiftCardController;
use App\Modules\Sales\Http\Controllers\KitchenController;
use App\Modules\Sales\Http\Controllers\OrderController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\PrintController;
use App\Modules\Sales\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api', 'identity.context'])->prefix('api/v1')->group(function (): void {
    Route::get('sales/catalog', [CatalogController::class, 'index'])->middleware('permission:sales.catalog.view');
    Route::post('sales/catalog/barcode/scan', [CatalogController::class, 'scan'])->middleware('permission:sales.catalog.view');

    Route::prefix('pos')->group(function (): void {
        Route::post('shifts', [PosController::class, 'openShift'])->name('sales.pos.shift.open')->middleware(['permission:pos.shifts.manage', 'pos.device']);
        Route::get('shifts/{shift}', [PosController::class, 'showShift'])->whereUlid('shift')->middleware('permission:pos.shifts.view');
        Route::post('shifts/{shift}/close', [PosController::class, 'closeShift'])->whereUlid('shift')->name('sales.pos.shift.close')->middleware(['permission:pos.shifts.manage', 'pos.device']);
        Route::post('drawers/sessions', [PosController::class, 'openDrawer'])->name('sales.pos.drawer.open')->middleware(['permission:pos.cash.manage', 'pos.device']);
        Route::post('drawers/sessions/{session}/close', [PosController::class, 'closeDrawer'])->whereUlid('session')->name('sales.pos.drawer.close')->middleware(['permission:pos.cash.manage', 'pos.device']);
        Route::post('drawers/sessions/{session}/movements', [PosController::class, 'movement'])->whereUlid('session')->name('sales.pos.movement')->middleware(['permission:pos.cash.manage', 'pos.device']);
        Route::post('cash-movements/{movement}/reverse', [PosController::class, 'reverseMovement'])->whereUlid('movement')->name('sales.pos.movement.reverse')->middleware(['permission:pos.cash.reverse', 'pos.device']);
        Route::get('floors', [PosController::class, 'floors'])->middleware('permission:pos.tables.view');
        Route::get('tables', [PosController::class, 'tables'])->middleware('permission:pos.tables.view');
        Route::post('table-sessions', [PosController::class, 'openTable'])->name('sales.pos.table.open')->middleware(['permission:pos.tables.manage', 'pos.device']);
        Route::post('table-sessions/{session}/close', [PosController::class, 'closeTable'])->whereUlid('session')->name('sales.pos.table.close')->middleware(['permission:pos.tables.manage', 'pos.device']);
    });

    Route::prefix('sales/orders')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('sales.orders.index')->middleware('permission:sales.orders.view');
        Route::post('/', [OrderController::class, 'store'])->name('sales.orders.store')->middleware(['permission:sales.orders.create', 'pos.device']);
        Route::get('{order}', [OrderController::class, 'show'])->whereUlid('order')->name('sales.orders.show')->middleware('permission:sales.orders.view');
        Route::get('{order}/tracking', [OrderController::class, 'tracking'])->whereUlid('order')->name('sales.orders.tracking')->middleware('permission:sales.orders.view');
        Route::post('{order}/items', [OrderController::class, 'addItem'])->whereUlid('order')->name('sales.orders.items.store')->middleware(['permission:sales.orders.update', 'pos.device']);
        Route::patch('{order}/items/{item}', [OrderController::class, 'updateItem'])->whereUlid(['order', 'item'])->name('sales.orders.items.update')->middleware(['permission:sales.orders.update', 'pos.device']);
        Route::delete('{order}/items/{item}', [OrderController::class, 'removeItem'])->whereUlid(['order', 'item'])->name('sales.orders.items.destroy')->middleware(['permission:sales.orders.update', 'pos.device']);
        Route::put('{order}/customer', [OrderController::class, 'customer'])->whereUlid('order')->name('sales.orders.customer')->middleware(['permission:sales.orders.update', 'pos.device']);
        Route::put('{order}/delivery', [OrderController::class, 'delivery'])->whereUlid('order')->name('sales.orders.delivery')->middleware(['permission:sales.orders.update', 'pos.device']);
        Route::post('{order}/place', [OrderController::class, 'place'])->whereUlid('order')->name('sales.orders.place')->middleware(['permission:sales.orders.place', 'pos.device']);
        Route::post('{order}/state', [OrderController::class, 'state'])->whereUlid('order')->name('sales.orders.state')->middleware(['permission:sales.orders.state', 'pos.device']);
        Route::post('{order}/discounts/manual', [OrderController::class, 'manualDiscount'])->whereUlid('order')->name('sales.orders.discount.manual')->middleware(['permission:sales.orders.discount', 'pos.device']);
        Route::post('{order}/discounts/membership', [OrderController::class, 'membershipDiscount'])->whereUlid('order')->name('sales.orders.discount.membership')->middleware(['permission:sales.orders.discount', 'pos.device']);
        Route::post('{order}/discounts/coupon', [OrderController::class, 'couponDiscount'])->whereUlid('order')->name('sales.orders.discount.coupon')->middleware(['permission:sales.orders.discount', 'pos.device']);
        Route::put('{order}/charges', [OrderController::class, 'charges'])->whereUlid('order')->name('sales.orders.charges')->middleware(['permission:sales.orders.charges', 'pos.device']);
        Route::put('{order}/service-charge', [OrderController::class, 'charges'])->whereUlid('order')->name('sales.orders.service-charge')->middleware(['permission:sales.orders.charges', 'pos.device']);
        Route::post('{order}/tips', [OrderController::class, 'tip'])->whereUlid('order')->name('sales.orders.tip')->middleware(['permission:sales.orders.update', 'pos.device']);
        Route::post('{order}/split', [OrderController::class, 'split'])->whereUlid('order')->name('sales.orders.split')->middleware(['permission:sales.orders.transfer', 'pos.device']);
        Route::post('{order}/merge', [OrderController::class, 'merge'])->whereUlid('order')->name('sales.orders.merge')->middleware(['permission:sales.orders.transfer', 'pos.device']);
        Route::post('{order}/transfer/order', [OrderController::class, 'merge'])->whereUlid('order')->name('sales.orders.transfer.order')->middleware(['permission:sales.orders.transfer', 'pos.device']);
        Route::post('{order}/transfer/table', [OrderController::class, 'transferTable'])->whereUlid('order')->name('sales.orders.transfer.table')->middleware(['permission:sales.orders.transfer', 'pos.device']);
        Route::post('{order}/transfer/customer', [OrderController::class, 'transferCustomer'])->whereUlid('order')->name('sales.orders.transfer.customer')->middleware(['permission:sales.orders.transfer', 'pos.device']);
        Route::get('{order}/quote', [OrderController::class, 'quote'])->whereUlid('order')->name('sales.orders.quote')->middleware('permission:sales.orders.view');
        Route::post('{order}/recalculate', [OrderController::class, 'recalculate'])->whereUlid('order')->name('sales.orders.recalculate')->middleware(['permission:sales.orders.update', 'pos.device']);
    });

    Route::prefix('sales/customers')->group(function (): void {
        Route::get('/', [CustomerController::class, 'index'])->middleware('permission:sales.customers.view');
        Route::get('search', [CustomerController::class, 'index'])->middleware('permission:sales.customers.view');
        Route::post('/', [CustomerController::class, 'store'])->name('sales.customers.store')->middleware(['permission:sales.customers.manage', 'pos.device']);
        Route::get('{customer}', [CustomerController::class, 'show'])->whereUlid('customer')->middleware('permission:sales.customers.view');
        Route::patch('{customer}', [CustomerController::class, 'update'])->whereUlid('customer')->middleware(['permission:sales.customers.manage', 'pos.device']);
        Route::get('{customer}/history', [CustomerController::class, 'history'])->whereUlid('customer')->middleware('permission:sales.customers.history');
        Route::post('{customer}/memberships', [CustomerController::class, 'membership'])->whereUlid('customer')->name('sales.customers.membership')->middleware(['permission:sales.customers.membership', 'pos.device']);
    });

    Route::prefix('sales/gift-cards')->group(function (): void {
        Route::post('issue', [GiftCardController::class, 'issue'])->name('sales.gifts.issue')->middleware(['permission:sales.gift_cards.issue', 'pos.device']);
        Route::post('load', [GiftCardController::class, 'load'])->name('sales.gifts.load')->middleware(['permission:sales.gift_cards.load', 'pos.device']);
        Route::post('balance', [GiftCardController::class, 'balance'])->name('sales.gifts.balance')->middleware('permission:sales.gift_cards.view');
        Route::post('redeem', [GiftCardController::class, 'redeem'])->name('sales.gifts.redeem')->middleware(['permission:sales.gift_cards.redeem', 'pos.device']);
        Route::post('reverse', [GiftCardController::class, 'reverse'])->name('sales.gifts.reverse')->middleware(['permission:sales.gift_cards.reverse', 'pos.device']);
    });

    Route::prefix('sales/billing')->group(function (): void {
        Route::get('payment-methods', [BillingController::class, 'methods'])->middleware('permission:sales.billing.view');
        Route::post('payments', [BillingController::class, 'capture'])->name('sales.billing.capture')->middleware(['permission:sales.billing.capture', 'pos.device']);
        Route::get('payments', [BillingController::class, 'payments'])->middleware('permission:sales.billing.view');
        Route::post('payments/{payment}/reverse', [BillingController::class, 'reverse'])->whereUlid('payment')->name('sales.billing.reverse')->middleware(['permission:sales.billing.reverse', 'pos.device']);
        Route::get('invoices/{invoice}', [BillingController::class, 'invoice'])->whereUlid('invoice')->middleware('permission:sales.billing.view');
        Route::get('receipts/{invoice}', [BillingController::class, 'receipt'])->whereUlid('invoice')->middleware('permission:sales.billing.view');
        Route::get('orders/{order}/settlement', [BillingController::class, 'settlement'])->whereUlid('order')->middleware('permission:sales.billing.view');
    });

    Route::prefix('sales/kds')->group(function (): void {
        Route::get('tickets', [KitchenController::class, 'queue'])->middleware('permission:sales.kds.view');
        Route::get('tickets/{ticket}', [KitchenController::class, 'show'])->whereUlid('ticket')->middleware('permission:sales.kds.view');
        Route::post('dispatch', [KitchenController::class, 'dispatch'])->name('sales.kds.dispatch')->middleware(['permission:sales.kds.dispatch', 'pos.device:edge']);
        foreach (['start', 'ready', 'bump', 'recall'] as $action) {
            Route::post("tickets/{ticket}/{$action}", [KitchenController::class, $action])->whereUlid('ticket')
                ->name('sales.kds.'.$action)
                ->middleware(['permission:sales.kds.manage', 'pos.device:edge']);
        }
    });

    Route::prefix('sales/printing')->group(function (): void {
        Route::post('jobs', [PrintController::class, 'store'])->name('sales.printing.store')->middleware(['permission:sales.printing.create', 'pos.device']);
        Route::get('jobs/{job}', [PrintController::class, 'show'])->whereUlid('job')->middleware('permission:sales.printing.view');
        Route::post('edge/jobs/claim', [PrintController::class, 'claim'])->middleware(['permission:sales.printing.edge', 'pos.device:edge']);
        Route::post('edge/jobs/{job}/complete', [PrintController::class, 'complete'])->whereUlid('job')->name('sales.printing.complete')->middleware(['permission:sales.printing.edge', 'pos.device:edge']);
        Route::post('edge/jobs/{job}/fail', [PrintController::class, 'fail'])->whereUlid('job')->name('sales.printing.fail')->middleware(['permission:sales.printing.edge', 'pos.device:edge']);
        Route::post('edge/jobs/{job}/retry', [PrintController::class, 'retry'])->whereUlid('job')->name('sales.printing.retry')->middleware(['permission:sales.printing.edge', 'pos.device:edge']);
    });

    Route::prefix('sales/sync')->group(function (): void {
        Route::post('push', [SyncController::class, 'push'])->name('sales.sync.push')->middleware(['permission:sales.sync.push', 'pos.device']);
        Route::get('pull', [SyncController::class, 'pull'])->name('sales.sync.pull')->middleware(['permission:sales.sync.pull', 'pos.device']);
        Route::post('cursor', [SyncController::class, 'acknowledge'])->name('sales.sync.cursor')->middleware(['permission:sales.sync.pull', 'pos.device']);
        Route::get('conflicts', [SyncController::class, 'conflicts'])->name('sales.sync.conflicts')->middleware('permission:sales.sync.conflicts.view');
        Route::post('conflicts/{conflict}/resolve', [SyncController::class, 'resolveConflict'])->whereUlid('conflict')->name('sales.sync.conflicts.resolve')->middleware('permission:sales.sync.conflicts.resolve');
    });
});
