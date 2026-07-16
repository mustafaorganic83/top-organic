<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\EmployeeLoan;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Salary advances and long-term loans: requested → approved → active → settled. */
final class LoanService
{
    use GuardsHrWrites;

    /** @return Collection<int, EmployeeLoan> */
    public function list(HrContext $context, ?string $employeeId = null, ?string $status = null): Collection
    {
        return EmployeeLoan::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(HrContext $context, string $id): EmployeeLoan
    {
        return EmployeeLoan::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw HrException::notFound('Loan not found.');
    }

    /** @param array<string, mixed> $data */
    public function request(HrContext $context, array $data): EmployeeLoan
    {
        return DB::transaction(function () use ($context, $data): EmployeeLoan {
            $amount = (int) $data['amount'];
            $installments = (int) ($data['installments'] ?? 1);
            $loan = EmployeeLoan::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'employee_id' => $data['employee_id'],
                'type' => $data['loan_type'] ?? 'salary_advance',
                'reference' => 'LN-'.uniqid(),
                'principal_amount' => $amount,
                'outstanding_amount' => $amount,
                'installments_count' => $installments,
                'installment_amount' => $installments > 0 ? (int) ceil($amount / $installments) : $amount,
                'reason' => $data['purpose'] ?? null,
                'status' => 'requested',
                'lock_version' => 0,
            ]);
            $this->audit($context, 'loan', $loan->id, 'requested');

            return $loan;
        }, 3);
    }

    public function approve(HrContext $context, string $id, int $version): EmployeeLoan
    {
        return DB::transaction(function () use ($context, $id, $version): EmployeeLoan {
            $loan = EmployeeLoan::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Loan not found.');
            $this->assertVersion($loan->lock_version, $version);
            if ($loan->status !== 'requested') {
                throw HrException::invalidTransition($loan->status, 'approved');
            }
            $loan->status = 'active';
            $loan->approved_by = (string) $context->userId;
            $loan->approved_at = now();
            $loan->lock_version++;
            $loan->save();
            $this->audit($context, 'loan', $loan->id, 'approved');

            return $loan->refresh();
        }, 3);
    }
}
