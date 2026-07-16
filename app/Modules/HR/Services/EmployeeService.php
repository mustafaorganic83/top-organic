<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Employee;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Employee CRUD with history recording. */
final class EmployeeService
{
    use GuardsHrWrites;

    /** @return Collection<int, Employee> */
    public function list(HrContext $context, ?string $status = null): Collection
    {
        return Employee::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
    }

    public function find(HrContext $context, string $id): Employee
    {
        return Employee::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->first()
            ?? throw HrException::notFound('Employee not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(HrContext $context, array $data): Employee
    {
        return DB::transaction(function () use ($context, $data): Employee {
            // 'employee_number' in API maps to 'code' column in DB
            $code = (string) ($data['employee_number'] ?? $data['code'] ?? '');
            $this->assertTenantUnique(Employee::class, $context, 'code', $code, null);
            $emp = Employee::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'department_id' => $data['department_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'code' => $code,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'job_title' => $data['position'] ?? $data['job_title'] ?? null,
                'hire_date' => $data['hire_date'] ?? null,
                'employment_type' => $data['employment_type'] ?? 'full_time',
                'status' => 'active',
                'currency' => $data['currency'] ?? 'IQD',
                'base_salary_amount' => (int) ($data['base_salary_amount'] ?? 0),
                'lock_version' => 0,
            ]);
            $this->audit($context, 'employee', $emp->id, 'created');

            return $emp;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(HrContext $context, string $id, int $version, array $data): Employee
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Employee {
            $emp = Employee::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Employee not found.');
            $this->assertVersion($emp->lock_version, $version);
            // Map API field names to DB column names
            if (isset($data['position'])) {
                $data['job_title'] = $data['position'];
                unset($data['position']);
            }
            $emp->fill(array_intersect_key($data, array_flip([
                'department_id', 'first_name', 'last_name', 'phone', 'email',
                'job_title', 'status', 'base_salary_amount', 'termination_date',
            ])));
            $emp->lock_version++;
            $emp->save();
            $this->audit($context, 'employee', $emp->id, 'updated', $data);

            return $emp->refresh();
        }, 3);
    }
}
