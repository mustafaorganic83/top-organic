<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services\Concerns;

use App\Models\InventoryAuditLog;
use App\Modules\Inventory\Data\InventoryContext;
use App\Modules\Inventory\Exceptions\InventoryException;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared write helpers for the Inventory services: optimistic-lock assertion,
 * per-branch reference uniqueness, and appending to the tamper-evident audit
 * trail. Every state transition (dispatch, receive, post, approve) writes an
 * audit row so the inventory history is reconstructable.
 */
trait GuardsInventoryWrites
{
    protected function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw InventoryException::conflict(
                InventoryException::STALE_VERSION,
                'The record was changed by another operation.',
            );
        }
    }

    /**
     * Assert a value is unique within the branch for the given model class.
     *
     * @param  class-string<Model>  $model
     */
    protected function assertBranchUnique(string $model, InventoryContext $context, string $column, string $value, ?string $ignoreId): void
    {
        $exists = $model::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where($column, $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw InventoryException::conflict(
                InventoryException::IN_USE,
                "A record with this {$column} already exists.",
                [$column => $value],
            );
        }
    }

    /**
     * Append an inventory audit row for an entity state change.
     *
     * @param  array<string, mixed>|null  $changes
     */
    protected function audit(InventoryContext $context, string $entityType, string $entityId, string $action, ?array $changes = null): void
    {
        InventoryAuditLog::withoutGlobalScopes()->create([
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
