<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Department;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use App\Modules\HR\Services\Concerns\GuardsHrWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Department CRUD with org-structure tree support. */
final class DepartmentService
{
    use GuardsHrWrites;

    /** @return Collection<int, Department> */
    public function list(HrContext $context): Collection
    {
        return Department::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    public function find(HrContext $context, string $id): Department
    {
        return Department::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->first()
            ?? throw HrException::notFound('Department not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(HrContext $context, array $data): Department
    {
        return DB::transaction(function () use ($context, $data): Department {
            $this->assertBranchUnique(Department::class, $context, 'code', (string) $data['code'], null);
            $dept = Department::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'parent_id' => $data['parent_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'] ?? 'department',
                'status' => 'active',
                'lock_version' => 0,
            ]);
            $this->audit($context, 'department', $dept->id, 'created');

            return $dept;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(HrContext $context, string $id, int $version, array $data): Department
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Department {
            $dept = Department::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw HrException::notFound('Department not found.');
            $this->assertVersion($dept->lock_version, $version);
            $dept->fill(array_intersect_key($data, array_flip(['name', 'type', 'parent_id', 'status'])));
            $dept->lock_version++;
            $dept->save();
            $this->audit($context, 'department', $dept->id, 'updated');

            return $dept->refresh();
        }, 3);
    }
}
