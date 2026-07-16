<?php

declare(strict_types=1);

use App\Modules\Procurement\Http\Controllers\ContractController;
use App\Modules\Procurement\Http\Controllers\InspectionController;
use App\Modules\Procurement\Http\Controllers\PaymentScheduleController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequestController;
use App\Modules\Procurement\Http\Controllers\QuotationController;
use App\Modules\Procurement\Http\Controllers\ReceivingController;
use App\Modules\Procurement\Http\Controllers\RfqController;
use App\Modules\Procurement\Http\Controllers\SupplierController;
use App\Modules\Procurement\Http\Controllers\SupplierEvaluationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api', 'identity.context'])
    ->prefix('api/v1/procurement')
    ->name('procurement.')
    ->group(function (): void {

        // ── Suppliers (Procurement view) ────────────────────────────────────
        Route::prefix('suppliers')->group(function (): void {
            Route::get('/', [SupplierController::class, 'index'])->middleware('permission:procurement.suppliers.view');
            Route::get('{supplier}', [SupplierController::class, 'show'])->whereUlid('supplier')->middleware('permission:procurement.suppliers.view');
            Route::patch('{supplier}', [SupplierController::class, 'update'])->whereUlid('supplier')->middleware('permission:procurement.suppliers.manage');

            // Supplier evaluations
            Route::get('{supplier}/evaluations', [SupplierEvaluationController::class, 'index'])->whereUlid('supplier')->middleware('permission:procurement.suppliers.view');
            Route::post('{supplier}/evaluations', [SupplierEvaluationController::class, 'store'])->whereUlid('supplier')->middleware('permission:procurement.suppliers.manage');
            Route::get('{supplier}/evaluations/{evaluation}', [SupplierEvaluationController::class, 'show'])->whereUlid('supplier')->whereUlid('evaluation')->middleware('permission:procurement.suppliers.view');
        });

        // ── RFQs ────────────────────────────────────────────────────────────
        Route::prefix('rfqs')->group(function (): void {
            Route::get('/', [RfqController::class, 'index'])->middleware('permission:procurement.rfq.view');
            Route::post('/', [RfqController::class, 'store'])->middleware('permission:procurement.rfq.manage');
            Route::get('{rfq}', [RfqController::class, 'show'])->whereUlid('rfq')->middleware('permission:procurement.rfq.view');
            Route::post('{rfq}/send', [RfqController::class, 'send'])->whereUlid('rfq')->middleware('permission:procurement.rfq.manage');
            Route::post('{rfq}/close', [RfqController::class, 'close'])->whereUlid('rfq')->middleware('permission:procurement.rfq.manage');
        });

        // ── Quotations ──────────────────────────────────────────────────────
        Route::prefix('quotations')->group(function (): void {
            Route::get('/', [QuotationController::class, 'index'])->middleware('permission:procurement.rfq.view');
            Route::post('/', [QuotationController::class, 'store'])->middleware('permission:procurement.rfq.manage');
            Route::get('{quotation}', [QuotationController::class, 'show'])->whereUlid('quotation')->middleware('permission:procurement.rfq.view');
            Route::post('{quotation}/shortlist', [QuotationController::class, 'shortlist'])->whereUlid('quotation')->middleware('permission:procurement.rfq.manage');
            Route::post('{quotation}/award', [QuotationController::class, 'award'])->whereUlid('quotation')->middleware('permission:procurement.rfq.manage');
            Route::post('{quotation}/reject', [QuotationController::class, 'reject'])->whereUlid('quotation')->middleware('permission:procurement.rfq.manage');
        });

        // ── Purchase Requests ───────────────────────────────────────────────
        Route::prefix('purchase-requests')->group(function (): void {
            Route::get('/', [PurchaseRequestController::class, 'index'])->middleware('permission:procurement.po.view');
            Route::post('/', [PurchaseRequestController::class, 'store'])->middleware('permission:procurement.po.manage');
            Route::get('{pr}', [PurchaseRequestController::class, 'show'])->whereUlid('pr')->middleware('permission:procurement.po.view');
            Route::post('{pr}/approve', [PurchaseRequestController::class, 'approve'])->whereUlid('pr')->middleware('permission:procurement.po.approve');
        });

        // ── Purchase Orders ─────────────────────────────────────────────────
        Route::prefix('purchase-orders')->group(function (): void {
            Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('permission:procurement.po.view');
            Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('permission:procurement.po.manage');
            Route::get('{po}', [PurchaseOrderController::class, 'show'])->whereUlid('po')->middleware('permission:procurement.po.view');
            Route::post('{po}/approve', [PurchaseOrderController::class, 'approve'])->whereUlid('po')->middleware('permission:procurement.po.approve');
            Route::post('{po}/send', [PurchaseOrderController::class, 'send'])->whereUlid('po')->middleware('permission:procurement.po.manage');
        });

        // ── Goods Receipts ──────────────────────────────────────────────────
        Route::prefix('receipts')->group(function (): void {
            Route::get('/', [ReceivingController::class, 'index'])->middleware('permission:procurement.receiving.manage');
            Route::post('/', [ReceivingController::class, 'store'])->middleware('permission:procurement.receiving.manage');
            Route::get('{receipt}', [ReceivingController::class, 'show'])->whereUlid('receipt')->middleware('permission:procurement.receiving.manage');
            Route::post('{receipt}/post', [ReceivingController::class, 'post'])->whereUlid('receipt')->middleware('permission:procurement.receiving.manage');

            // Inspections nested under receipts
            Route::post('{receipt}/inspection', [InspectionController::class, 'store'])->whereUlid('receipt')->middleware('permission:procurement.inspection.manage');
        });

        // ── Inspections ─────────────────────────────────────────────────────
        Route::prefix('inspections')->group(function (): void {
            Route::get('/', [InspectionController::class, 'index'])->middleware('permission:procurement.inspection.manage');
            Route::get('{inspection}', [InspectionController::class, 'show'])->whereUlid('inspection')->middleware('permission:procurement.inspection.manage');
            Route::post('{inspection}/pass', [InspectionController::class, 'pass'])->whereUlid('inspection')->middleware('permission:procurement.inspection.manage');
            Route::post('{inspection}/fail', [InspectionController::class, 'fail'])->whereUlid('inspection')->middleware('permission:procurement.inspection.manage');
        });

        // ── Vendor Contracts ────────────────────────────────────────────────
        Route::prefix('contracts')->group(function (): void {
            Route::get('/', [ContractController::class, 'index'])->middleware('permission:procurement.contracts.manage');
            Route::post('/', [ContractController::class, 'store'])->middleware('permission:procurement.contracts.manage');
            Route::get('{contract}', [ContractController::class, 'show'])->whereUlid('contract')->middleware('permission:procurement.contracts.manage');
            Route::post('{contract}/terminate', [ContractController::class, 'terminate'])->whereUlid('contract')->middleware('permission:procurement.contracts.manage');
        });

        // ── Payment Schedules ───────────────────────────────────────────────
        Route::prefix('payment-schedules')->group(function (): void {
            Route::get('/', [PaymentScheduleController::class, 'index'])->middleware('permission:procurement.po.view');
            Route::post('/', [PaymentScheduleController::class, 'store'])->middleware('permission:procurement.po.manage');
            Route::get('{schedule}', [PaymentScheduleController::class, 'show'])->whereUlid('schedule')->middleware('permission:procurement.po.view');
            Route::post('{schedule}/pay', [PaymentScheduleController::class, 'markPaid'])->whereUlid('schedule')->middleware('permission:procurement.po.manage');
        });
    });
