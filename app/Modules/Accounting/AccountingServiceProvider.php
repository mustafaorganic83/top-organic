<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use Illuminate\Support\ServiceProvider;

/**
 * Accounting ERP module. Registers versioned API routes for Chart of Accounts,
 * General Ledger, AP, AR, Cash/Bank, Budget, Cost Centers, Projects, Journal
 * Entries, Auto-posting, and financial reports (Trial Balance, P&L, Balance
 * Sheet, Cash Flow, Restaurant Profitability, Branch Accounting).
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
