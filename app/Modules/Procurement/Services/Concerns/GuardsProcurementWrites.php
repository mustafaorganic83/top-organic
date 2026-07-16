<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services\Concerns;

use App\Models\ProcurementAuditLog;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared write helpers for Procurement services: optimistic-lock assertion,
 * per-branch reference uniqueness, and appending to the tamper-evident
 * procurement audit trail. Every state transition writes an audit row.
 */
trait GuardsProcurementWrites
{
    protected function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ProcurementException::conflict(
                ProcurementException::STALE_VERSION,
                'The record was changed by another operation.',
            );
        }
    }

    /**
     * Assert a value is unique within the branch for the given model class.
     *
     * @param  class-string<Model>  $model
     */
    protected function assertBranchUnique(string $model, ProcurementContext $context, string $column, string $value, ?string $ignoreId): void
    {
        $exists = $model::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where($column, $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw ProcurementException::conflict(
                ProcurementException::IN_USE,
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
    protected function assertTenantUnique(string $model, ProcurementContext $context, string $column, string $value, ?string $ignoreId): void
    {
        $exists = $model::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where($column, $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw ProcurementException::conflict(
                ProcurementException::IN_USE,
                "A record with this {$column} already exists.",
                [$column => $value],
            );
        }
    }

    /**
     * Append a procurement audit row for an entity state change.
     *
     * @param  array<string, mixed>|null  $changes
     */
    protected function audit(ProcurementContext $context, string $entityType, string $entityId, string $action, ?array $changes = null): void
    {
        ProcurementAuditLog::withoutGlobalScopes()->create([
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
