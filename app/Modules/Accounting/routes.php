<?php

declare(strict_types=1);

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\ApController;
use App\Modules\Accounting\Http\Controllers\ArController;
use App\Modules\Accounting\Http\Controllers\BankController;
use App\Modules\Accounting\Http\Controllers\BudgetController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\ProjectController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use App\Modules\Accounting\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:api', 'identity.context'])
    ->prefix('api/v1/accounting')
    ->name('accounting.')
    ->group(function (): void {

        // --- Chart of Accounts ---
        Route::prefix('accounts')->group(function (): void {
            Route::get('/', [AccountController::class, 'index'])->middleware('permission:accounting.view');
            Route::post('/', [AccountController::class, 'store'])->middleware('permission:accounting.manage');
            Route::get('{account}', [AccountController::class, 'show'])->whereUlid('account')->middleware('permission:accounting.view');
            Route::patch('{account}', [AccountController::class, 'update'])->whereUlid('account')->middleware('permission:accounting.manage');
            Route::delete('{account}', [AccountController::class, 'destroy'])->whereUlid('account')->middleware('permission:accounting.manage');
        });

        // --- Cost Centers ---
        Route::prefix('cost-centers')->group(function (): void {
            Route::get('/', [AccountController::class, 'costCenters'])->middleware('permission:accounting.view');
            Route::post('/', [AccountController::class, 'storeCostCenter'])->middleware('permission:accounting.manage');
            Route::patch('{costCenter}', [AccountController::class, 'updateCostCenter'])->whereUlid('costCenter')->middleware('permission:accounting.manage');
        });

        // --- Projects ---
        Route::prefix('projects')->group(function (): void {
            Route::get('/', [ProjectController::class, 'index'])->middleware('permission:accounting.view');
            Route::post('/', [ProjectController::class, 'store'])->middleware('permission:accounting.manage');
            Route::get('{project}', [ProjectController::class, 'show'])->whereUlid('project')->middleware('permission:accounting.view');
            Route::patch('{project}', [ProjectController::class, 'update'])->whereUlid('project')->middleware('permission:accounting.manage');
        });

        // --- Journal Entries ---
        Route::prefix('journals')->group(function (): void {
            Route::get('/', [JournalController::class, 'index'])->middleware('permission:accounting.journal.view');
            Route::post('/', [JournalController::class, 'store'])->middleware('permission:accounting.journal.create');
            Route::get('{journal}', [JournalController::class, 'show'])->whereUlid('journal')->middleware('permission:accounting.journal.view');
            Route::post('{journal}/post', [JournalController::class, 'post'])->whereUlid('journal')->middleware('permission:accounting.journal.post');
            Route::post('{journal}/reverse', [JournalController::class, 'reverse'])->whereUlid('journal')->middleware('permission:accounting.journal.post');
        });

        // --- Suppliers (AP) ---
        Route::prefix('suppliers')->group(function (): void {
            Route::get('/', [SupplierController::class, 'index'])->middleware('permission:accounting.ap.view');
            Route::post('/', [SupplierController::class, 'store'])->middleware('permission:accounting.ap.manage');
            Route::get('{supplier}', [SupplierController::class, 'show'])->whereUlid('supplier')->middleware('permission:accounting.ap.view');
            Route::patch('{supplier}', [SupplierController::class, 'update'])->whereUlid('supplier')->middleware('permission:accounting.ap.manage');
        });

        // --- Accounts Payable ---
        Route::prefix('ap')->group(function (): void {
            Route::get('invoices', [ApController::class, 'invoices'])->middleware('permission:accounting.ap.view');
            Route::post('invoices', [ApController::class, 'storeInvoice'])->middleware('permission:accounting.ap.manage');
            Route::get('invoices/{invoice}', [ApController::class, 'showInvoice'])->whereUlid('invoice')->middleware('permission:accounting.ap.view');
            Route::post('invoices/{invoice}/approve', [ApController::class, 'approveInvoice'])->whereUlid('invoice')->middleware('permission:accounting.ap.manage');
            Route::post('invoices/{invoice}/pay', [ApController::class, 'payInvoice'])->whereUlid('invoice')->middleware('permission:accounting.ap.pay');
            Route::get('aging', [ApController::class, 'aging'])->middleware('permission:accounting.ap.view');
        });

        // --- Accounts Receivable ---
        Route::prefix('ar')->group(function (): void {
            Route::get('invoices', [ArController::class, 'invoices'])->middleware('permission:accounting.ar.view');
            Route::post('invoices', [ArController::class, 'storeInvoice'])->middleware('permission:accounting.ar.manage');
            Route::get('invoices/{invoice}', [ArController::class, 'showInvoice'])->whereUlid('invoice')->middleware('permission:accounting.ar.view');
            Route::get('aging', [ArController::class, 'aging'])->middleware('permission:accounting.ar.view');
        });

        // --- Bank & Cash ---
        Route::prefix('bank-accounts')->group(function (): void {
            Route::get('/', [BankController::class, 'index'])->middleware('permission:accounting.bank.view');
            Route::post('/', [BankController::class, 'store'])->middleware('permission:accounting.bank.manage');
            Route::get('{bank}', [BankController::class, 'show'])->whereUlid('bank')->middleware('permission:accounting.bank.view');
            Route::post('{bank}/reconcile', [BankController::class, 'reconcile'])->whereUlid('bank')->middleware('permission:accounting.bank.manage');
            Route::get('{bank}/statement', [BankController::class, 'statement'])->whereUlid('bank')->middleware('permission:accounting.bank.view');
        });

        // --- Budget ---
        Route::prefix('budgets')->group(function (): void {
            Route::get('/', [BudgetController::class, 'index'])->middleware('permission:accounting.budget.view');
            Route::post('/', [BudgetController::class, 'store'])->middleware('permission:accounting.budget.manage');
            Route::patch('{budget}', [BudgetController::class, 'update'])->whereUlid('budget')->middleware('permission:accounting.budget.manage');
            Route::post('{budget}/approve', [BudgetController::class, 'approve'])->whereUlid('budget')->middleware('permission:accounting.budget.manage');
            Route::get('variance', [BudgetController::class, 'variance'])->middleware('permission:accounting.budget.view');
        });

        // --- Financial Reports ---
        Route::prefix('reports')->group(function (): void {
            Route::get('trial-balance', [ReportController::class, 'trialBalance'])->middleware('permission:accounting.reports.view');
            Route::get('income-statement', [ReportController::class, 'incomeStatement'])->middleware('permission:accounting.reports.view');
            Route::get('balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('permission:accounting.reports.view');
            Route::get('cash-flow', [ReportController::class, 'cashFlow'])->middleware('permission:accounting.reports.view');
            Route::get('profitability', [ReportController::class, 'profitability'])->middleware('permission:accounting.reports.view');
            Route::get('branch-accounting', [ReportController::class, 'branchAccounting'])->middleware('permission:accounting.reports.view');
            Route::get('general-ledger', [ReportController::class, 'generalLedger'])->middleware('permission:accounting.reports.view');
        });
    });
