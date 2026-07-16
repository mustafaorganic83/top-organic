<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Modules\Accounting\Data\AccountingContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Financial reports: Trial Balance, Income Statement (P&L), Balance Sheet,
 * Cash Flow (indirect method), Restaurant Profitability, Branch Accounting,
 * and the raw General Ledger detail report.
 *
 * All amounts are integer minor-units; callers format for display.
 * Reports are computed from posted journal entry lines — not from cached
 * balances — ensuring they always match the authoritative ledger.
 */
final class ReportService
{
    /**
     * Trial Balance: net debit/credit movement per account for a period.
     *
     * @return array<string, mixed>
     */
    public function trialBalance(AccountingContext $context, string $fiscalYear, ?int $periodMonth = null): array
    {
        $rows = $this->aggregateLines($context, $fiscalYear, $periodMonth, null, null);
        $totalDebit = 0;
        $totalCredit = 0;
        $accounts = [];
        foreach ($rows as $row) {
            $totalDebit += (int) $row->total_debit;
            $totalCredit += (int) $row->total_credit;
            $accounts[] = [
                'account_id' => $row->account_id,
                'account_code' => $row->code,
                'account_name' => $row->name,
                'account_type' => $row->type,
                'total_debit' => (int) $row->total_debit,
                'total_credit' => (int) $row->total_credit,
                'net_balance' => (int) $row->total_debit - (int) $row->total_credit,
            ];
        }

        return [
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'accounts' => $accounts,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $totalDebit === $totalCredit,
        ];
    }

    /**
     * Income Statement (P&L): Revenue minus Expenses for a period.
     *
     * @return array<string, mixed>
     */
    public function incomeStatement(AccountingContext $context, string $fiscalYear, ?int $periodMonth = null, ?string $branchId = null): array
    {
        $rows = $this->aggregateLines($context, $fiscalYear, $periodMonth, null, $branchId);
        $revenue = 0;
        $cogs = 0;
        $expenses = 0;
        $revenueLines = [];
        $expenseLines = [];
        foreach ($rows as $row) {
            $net = (int) $row->total_credit - (int) $row->total_debit;
            match ($row->type) {
                'revenue' => ($revenue += $net) && ($revenueLines[] = $this->line($row, $net)),
                'cogs' => $cogs += (int) $row->total_debit - (int) $row->total_credit,
                'expense' => ($expenses += (int) $row->total_debit - (int) $row->total_credit) && ($expenseLines[] = $this->line($row, (int) $row->total_debit - (int) $row->total_credit)),
                default => null,
            };
        }
        $grossProfit = $revenue - $cogs;
        $netIncome = $grossProfit - $expenses;

        return [
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'branch_id' => $branchId,
            'revenue' => $revenue,
            'revenue_lines' => $revenueLines,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin_bps' => $revenue > 0 ? (int) round($grossProfit / $revenue * 10000) : 0,
            'expenses' => $expenses,
            'expense_lines' => $expenseLines,
            'net_income' => $netIncome,
            'net_margin_bps' => $revenue > 0 ? (int) round($netIncome / $revenue * 10000) : 0,
        ];
    }

    /**
     * Balance Sheet: snapshot of Assets, Liabilities, Equity as at end of period.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(AccountingContext $context, string $fiscalYear, ?int $periodMonth = null): array
    {
        // Cumulative from inception to end of requested period
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.tenant_id', $context->tenantId)
            ->where('je.status', 'posted')
            ->where(fn ($q) => $q->where('je.fiscal_year', '<', $fiscalYear)
                ->orWhere(fn ($q2) => $q2->where('je.fiscal_year', $fiscalYear)
                    ->when($periodMonth, fn ($q3) => $q3->where('je.period_month', '<=', $periodMonth))))
            ->whereIn('a.type', ['asset', 'liability', 'equity'])
            ->select('jel.account_id', 'a.code', 'a.name', 'a.type',
                DB::raw('SUM(jel.debit_amount) as total_debit'),
                DB::raw('SUM(jel.credit_amount) as total_credit'))
            ->groupBy('jel.account_id', 'a.code', 'a.name', 'a.type')
            ->get();

        $assets = 0;
        $liabilities = 0;
        $equity = 0;
        $assetLines = [];
        $liabilityLines = [];
        $equityLines = [];
        foreach ($rows as $row) {
            $net = match ($row->type) {
                'asset' => (int) $row->total_debit - (int) $row->total_credit,
                'liability', 'equity' => (int) $row->total_credit - (int) $row->total_debit,
                default => 0,
            };
            match ($row->type) {
                'asset' => ($assets += $net) && ($assetLines[] = $this->line($row, $net)),
                'liability' => ($liabilities += $net) && ($liabilityLines[] = $this->line($row, $net)),
                'equity' => ($equity += $net) && ($equityLines[] = $this->line($row, $net)),
                default => null,
            };
        }

        return [
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'total_assets' => $assets,
            'asset_lines' => $assetLines,
            'total_liabilities' => $liabilities,
            'liability_lines' => $liabilityLines,
            'total_equity' => $equity,
            'equity_lines' => $equityLines,
            'is_balanced' => $assets === ($liabilities + $equity),
        ];
    }

    /**
     * Cash Flow (indirect method): Net Income adjusted for non-cash items.
     * Simplified: uses net income and changes in AR/AP/Bank as proxies.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(AccountingContext $context, string $fiscalYear, ?int $periodMonth = null): array
    {
        $pl = $this->incomeStatement($context, $fiscalYear, $periodMonth);
        $netIncome = $pl['net_income'];
        // Changes in working capital (AR, AP, Bank)
        $rows = $this->aggregateLines($context, $fiscalYear, $periodMonth, null, null);
        $arChange = 0;
        $apChange = 0;
        $cashChange = 0;
        foreach ($rows as $row) {
            $netDebit = (int) $row->total_debit - (int) $row->total_credit;
            match ($row->subtype ?? '') {
                'receivable' => $arChange += -$netDebit,  // increase in AR reduces cash
                'payable' => $apChange += $netDebit,       // increase in AP increases cash
                'bank', 'cash' => $cashChange += $netDebit,
                default => null,
            };
        }
        $operatingCashFlow = $netIncome + $apChange - $arChange;

        return [
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'net_income' => $netIncome,
            'changes_in_ar' => -$arChange,
            'changes_in_ap' => $apChange,
            'operating_cash_flow' => $operatingCashFlow,
            'net_change_in_cash' => $cashChange,
        ];
    }

    /**
     * Restaurant Profitability: P&L with food-cost % and labour-cost % layers.
     *
     * @return array<string, mixed>
     */
    public function profitability(AccountingContext $context, string $fiscalYear, ?int $periodMonth = null): array
    {
        $pl = $this->incomeStatement($context, $fiscalYear, $periodMonth);
        $revenue = $pl['revenue'];
        // Separate food cost (cogs) from labour (expenses with subtype='labour')
        $rows = $this->aggregateLines($context, $fiscalYear, $periodMonth, null, null);
        $labourCost = 0;
        foreach ($rows as $row) {
            if ($row->type === 'expense' && ($row->subtype ?? '') === 'labour') {
                $labourCost += (int) $row->total_debit - (int) $row->total_credit;
            }
        }
        $foodCost = $pl['cogs'];
        $primeCost = $foodCost + $labourCost;
        $primeCostBps = $revenue > 0 ? (int) round($primeCost / $revenue * 10000) : 0;

        return array_merge($pl, [
            'food_cost' => $foodCost,
            'food_cost_bps' => $revenue > 0 ? (int) round($foodCost / $revenue * 10000) : 0,
            'labour_cost' => $labourCost,
            'labour_cost_bps' => $revenue > 0 ? (int) round($labourCost / $revenue * 10000) : 0,
            'prime_cost' => $primeCost,
            'prime_cost_bps' => $primeCostBps,
        ]);
    }

    /**
     * Branch Accounting: income statement per branch for a period.
     *
     * @return array<string, mixed>
     */
    public function branchAccounting(AccountingContext $context, string $fiscalYear, ?int $periodMonth = null): array
    {
        $branchIds = DB::table('journal_entries')
            ->where('tenant_id', $context->tenantId)
            ->where('status', 'posted')
            ->where('fiscal_year', $fiscalYear)
            ->when($periodMonth, fn ($q) => $q->where('period_month', $periodMonth))
            ->whereNotNull('branch_id')
            ->distinct()->pluck('branch_id');

        $branches = [];
        foreach ($branchIds as $branchId) {
            $branches[] = array_merge(
                ['branch_id' => $branchId],
                $this->incomeStatement($context, $fiscalYear, $periodMonth, $branchId),
            );
        }

        return [
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'branches' => $branches,
        ];
    }

    /**
     * General Ledger: all posted movements for an account in a date range.
     *
     * @return array<string, mixed>
     */
    public function generalLedger(AccountingContext $context, string $accountId, ?string $from, ?string $to): array
    {
        $lines = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.tenant_id', $context->tenantId)
            ->where('jel.account_id', $accountId)
            ->where('je.status', 'posted')
            ->when($from, fn ($q) => $q->where('je.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('je.entry_date', '<=', $to))
            ->select('je.reference', 'je.entry_date', 'je.description as je_description',
                'jel.debit_amount', 'jel.credit_amount', 'jel.description', 'jel.line_number')
            ->orderBy('je.entry_date')->orderBy('je.created_at')
            ->get();

        $runningBalance = 0;
        $rows = $lines->map(function ($line) use (&$runningBalance) {
            $runningBalance += (int) $line->debit_amount - (int) $line->credit_amount;

            return [
                'reference' => $line->reference,
                'entry_date' => $line->entry_date,
                'description' => $line->description ?? $line->je_description,
                'debit_amount' => (int) $line->debit_amount,
                'credit_amount' => (int) $line->credit_amount,
                'running_balance' => $runningBalance,
            ];
        });

        return [
            'account_id' => $accountId,
            'from' => $from,
            'to' => $to,
            'lines' => $rows->all(),
            'closing_balance' => $runningBalance,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function aggregateLines(AccountingContext $context, string $fiscalYear, ?int $periodMonth, ?string $accountType, ?string $branchId): Collection
    {
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.tenant_id', $context->tenantId)
            ->where('je.status', 'posted')
            ->where('je.fiscal_year', $fiscalYear)
            ->when($periodMonth, fn ($q) => $q->where('je.period_month', $periodMonth))
            ->when($accountType, fn ($q) => $q->where('a.type', $accountType))
            ->when($branchId, fn ($q) => $q->where('je.branch_id', $branchId))
            ->select('jel.account_id', 'a.code', 'a.name', 'a.type', 'a.subtype',
                DB::raw('SUM(jel.debit_amount) as total_debit'),
                DB::raw('SUM(jel.credit_amount) as total_credit'))
            ->groupBy('jel.account_id', 'a.code', 'a.name', 'a.type', 'a.subtype')
            ->get();
    }

    /** @param object $row */
    private function line(object $row, int $net): array
    {
        return [
            'account_id' => $row->account_id,
            'account_code' => $row->code,
            'account_name' => $row->name,
            'amount' => $net,
        ];
    }
}
