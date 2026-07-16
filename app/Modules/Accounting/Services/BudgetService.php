<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\Budget;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Budget management: define budgeted amounts per account/cost-center/period.
 * Actual amounts are maintained via JournalService::updateBudgetActuals when
 * journal entries are posted. Variance reporting compares budget vs actuals.
 */
final class BudgetService
{
    use GuardsAccountingWrites;

    /** @return Collection<int, Budget> */
    public function list(AccountingContext $context, ?string $fiscalYear = null, ?string $branchId = null): Collection
    {
        return Budget::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($fiscalYear, fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('account', 'costCenter')->orderBy('fiscal_year')->orderBy('period_month')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(AccountingContext $context, array $data): Budget
    {
        return DB::transaction(function () use ($context, $data): Budget {
            $exists = Budget::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('account_id', $data['account_id'])
                ->where('cost_center_id', $data['cost_center_id'] ?? null)
                ->where('fiscal_year', $data['fiscal_year'])
                ->where('period_month', $data['period_month'])
                ->exists();
            if ($exists) {
                throw AccountingException::conflict(
                    AccountingException::IN_USE,
                    'A budget for this account/period already exists. Use update.',
                );
            }

            return Budget::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? $context->branchId,
                'account_id' => $data['account_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'fiscal_year' => $data['fiscal_year'],
                'period_month' => (int) $data['period_month'],
                'budgeted_amount' => (int) $data['budgeted_amount'],
                'actual_amount' => 0,
                'status' => 'draft',
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(AccountingContext $context, string $id, array $data): Budget
    {
        return DB::transaction(function () use ($context, $id, $data): Budget {
            $budget = Budget::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Budget not found.');
            if ($budget->status === 'approved') {
                throw AccountingException::invalidState('Approved budgets cannot be modified.');
            }
            $budget->budgeted_amount = (int) ($data['budgeted_amount'] ?? $budget->budgeted_amount);
            $budget->save();

            return $budget->refresh();
        }, 3);
    }

    public function approve(AccountingContext $context, string $id): Budget
    {
        return DB::transaction(function () use ($context, $id): Budget {
            $budget = Budget::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Budget not found.');
            if ($budget->status !== 'draft') {
                throw AccountingException::invalidState('Only a draft budget can be approved.');
            }
            $budget->status = 'approved';
            $budget->save();

            return $budget->refresh();
        }, 3);
    }

    /**
     * Variance report: budget vs actual for a fiscal year, grouped by account.
     *
     * @return array<string, mixed>
     */
    public function variance(AccountingContext $context, string $fiscalYear, ?int $periodMonth = null): array
    {
        $rows = Budget::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('fiscal_year', $fiscalYear)
            ->when($periodMonth, fn ($q) => $q->where('period_month', $periodMonth))
            ->with('account', 'costCenter')
            ->get();

        $grouped = $rows->groupBy('account_id')->map(fn ($group) => [
            'account_code' => $group->first()->account?->code,
            'account_name' => $group->first()->account?->name,
            'budgeted' => $group->sum('budgeted_amount'),
            'actual' => $group->sum('actual_amount'),
            'variance' => $group->sum('budgeted_amount') - $group->sum('actual_amount'),
        ])->values();

        return [
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
            'rows' => $grouped->all(),
            'total_budgeted' => $rows->sum('budgeted_amount'),
            'total_actual' => $rows->sum('actual_amount'),
            'total_variance' => $rows->sum('budgeted_amount') - $rows->sum('actual_amount'),
        ];
    }
}
