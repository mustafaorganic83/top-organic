<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Payroll run lifecycle: draft → calculated → approved → paid. Generates payslips. */
final class PayrollService
{
    use GuardsHrWrites;

    /** @return Collection<int, PayrollRun> */
    public function listRuns(HrContext $context): Collection
    {
        return PayrollRun::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->orderByDesc('period_start')
            ->get();
    }

    public function findRun(HrContext $context, string $id): PayrollRun
    {
        return PayrollRun::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw HrException::notFound('Payroll run not found.');
    }

    /** @param array<string, mixed> $data */
    public function createRun(HrContext $context, array $data): PayrollRun
    {
        return DB::transaction(function () use ($context, $data): PayrollRun {
            $run = PayrollRun::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'reference' => 'PAY-'.strtoupper(substr(str_replace('-', '', $data['period_start']), 0, 6)).'-'.uniqid(),
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'status' => 'draft',
                'total_amount' => 0,
                'lock_version' => 0,
            ]);
            $this->audit($context, 'payroll_run', $run->id, 'created');

            return $run;
        }, 3);
    }

    /** Calculate payslips for all active employees in the branch. */
    public function calculate(HrContext $context, string $runId, int $version): PayrollRun
    {
        return DB::transaction(function () use ($context, $runId, $version): PayrollRun {
            $run = PayrollRun::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($runId)->lockForUpdate()->first()
                ?? throw HrException::notFound('Payroll run not found.');
            $this->assertVersion($run->lock_version, $version);
            if ($run->status !== 'draft') {
                throw HrException::invalidTransition($run->status, 'calculated');
            }
            $employees = Employee::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)
                ->where('status', 'active')->get();
            $total = 0;
            foreach ($employees as $emp) {
                $bonuses = PayrollAdjustment::withoutGlobalScopes()
                    ->where('payroll_run_id', $run->id)
                    ->where('employee_id', $emp->id)
                    ->where('type', 'bonus')->sum('amount');
                $penalties = PayrollAdjustment::withoutGlobalScopes()
                    ->where('payroll_run_id', $run->id)
                    ->where('employee_id', $emp->id)
                    ->where('type', 'penalty')->sum('amount');
                $gross = $emp->base_salary_amount + $bonuses;
                $net = $gross - $penalties;
                Payslip::withoutGlobalScopes()->updateOrCreate(
                    ['payroll_run_id' => $run->id, 'employee_id' => $emp->id],
                    [
                        'tenant_id' => $context->tenantId,
                        'branch_id' => $context->branchId,
                        'base_amount' => $emp->base_salary_amount,
                        'bonus_amount' => (int) $bonuses,
                        'penalty_amount' => (int) $penalties,
                        'loan_deduction_amount' => 0,
                        'gross_amount' => (int) $gross,
                        'deductions_amount' => (int) $penalties,
                        'net_amount' => (int) $net,
                        'lock_version' => 0,
                    ],
                );
                $total += $net;
            }
            $run->status = 'calculated';
            $run->total_amount = $total;
            $run->calculated_at = now();
            $run->lock_version++;
            $run->save();
            $this->audit($context, 'payroll_run', $run->id, 'calculated');

            return $run->refresh();
        }, 3);
    }

    public function approve(HrContext $context, string $runId, int $version): PayrollRun
    {
        return DB::transaction(function () use ($context, $runId, $version): PayrollRun {
            $run = PayrollRun::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($runId)->lockForUpdate()->first()
                ?? throw HrException::notFound('Payroll run not found.');
            $this->assertVersion($run->lock_version, $version);
            if ($run->status !== 'calculated') {
                throw HrException::invalidTransition($run->status, 'approved');
            }
            $run->status = 'approved';
            $run->approved_at = now();
            $run->lock_version++;
            $run->save();
            $this->audit($context, 'payroll_run', $run->id, 'approved');

            return $run->refresh();
        }, 3);
    }

    public function findPayslip(HrContext $context, string $runId, string $employeeId): Payslip
    {
        return Payslip::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('payroll_run_id', $runId)
            ->where('employee_id', $employeeId)->first()
            ?? throw HrException::notFound('Payslip not found.');
    }

    /** @param array<string, mixed> $data */
    public function addAdjustment(HrContext $context, string $runId, array $data): PayrollAdjustment
    {
        return DB::transaction(function () use ($context, $runId, $data): PayrollAdjustment {
            $run = PayrollRun::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($runId)->first()
                ?? throw HrException::notFound('Payroll run not found.');
            if ($run->status !== 'draft') {
                throw HrException::invalidState('Adjustments can only be added to draft payroll runs.');
            }

            return PayrollAdjustment::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'payroll_run_id' => $runId,
                'employee_id' => $data['employee_id'],
                'type' => $data['type'],
                'amount' => (int) $data['amount'],
                'reason' => $data['reason'] ?? null,
                'lock_version' => 0,
            ]);
        }, 3);
    }
}
