<?php

declare(strict_types=1);

namespace App\Modules\HR\Services\Concerns;

use App\Models\EmployeeHistory;
use App\Modules\HR\Data\HrContext;
use App\Modules\HR\Exceptions\HrException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared write helpers for HR services: optimistic-lock assertion, per-branch
 * and per-tenant uniqueness, and appending to the tamper-evident employee
 * history trail. Every state transition writes a history row.
 */
trait GuardsHrWrites
{
    protected function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw HrException::conflict(
                HrException::STALE_VERSION,
                'The record was changed by another operation.',
            );
        }
    }

    /**
     * Assert a value is unique within the branch for the given model class.
     *
     * @param  class-string<Model>  $model
     */
    protected function assertBranchUnique(string $model, HrContext $context, string $column, string $value, ?string $ignoreId): void
    {
        $exists = $model::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where($column, $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw HrException::conflict(
                HrException::IN_USE,
                "A record with this {$column} already exists.",
                [$column => $value],
            );
        }
    }

    /**
     * Assert a value is unique within the tenant for the given model class.
     *
     * @param  class-string<Model>  $model
     */
    protected function assertTenantUnique(string $model, HrContext $context, string $column, string $value, ?string $ignoreId): void
    {
        $exists = $model::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where($column, $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw HrException::conflict(
                HrException::IN_USE,
                "A record with this {$column} already exists.",
                [$column => $value],
            );
        }
    }

    /**
     * Append an employee-history audit row for an entity state change.
     *
     * @param  array<string, mixed>|null  $changes
     */
    protected function audit(HrContext $context, string $entityType, string $entityId, string $action, ?array $changes = null): void
    {
        EmployeeHistory::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'branch_id' => $context->branchId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'changes' => $changes,
            'actor_id' => $context->userId,
            'device_id' => $context->deviceId,
            'occurred_at' => now(),
        ]);
    }
}
