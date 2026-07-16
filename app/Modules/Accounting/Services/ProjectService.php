<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\AccountingProject;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Project-based accounting: track costs and revenue per project.
 * Journal lines can be tagged with a project_id to roll up project P&L.
 */
final class ProjectService
{
    use GuardsAccountingWrites;

    /** @return Collection<int, AccountingProject> */
    public function list(AccountingContext $context, ?string $status = null): Collection
    {
        return AccountingProject::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('code')->get();
    }

    public function find(AccountingContext $context, string $id): AccountingProject
    {
        return AccountingProject::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw AccountingException::notFound('Project not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(AccountingContext $context, array $data): AccountingProject
    {
        return DB::transaction(function () use ($context, $data): AccountingProject {
            $this->assertTenantUnique(AccountingProject::class, $context, 'code', (string) $data['code'], null);

            return AccountingProject::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? $context->branchId,
                'code' => $data['code'],
                'name' => $data['name'],
                'status' => $data['status'] ?? 'active',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'budget_amount' => (int) ($data['budget_amount'] ?? 0),
                'lock_version' => 0,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(AccountingContext $context, string $id, int $version, array $data): AccountingProject
    {
        return DB::transaction(function () use ($context, $id, $version, $data): AccountingProject {
            $project = AccountingProject::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Project not found.');
            $this->assertVersion($project->lock_version, $version);
            $project->fill(array_intersect_key($data, array_flip([
                'name', 'status', 'start_date', 'end_date', 'budget_amount',
            ])));
            $project->lock_version++;
            $project->save();

            return $project->refresh();
        }, 3);
    }
}
