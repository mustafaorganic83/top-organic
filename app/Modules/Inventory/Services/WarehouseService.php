<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\Warehouse;
use App\Modules\Inventory\Data\InventoryContext;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\Concerns\GuardsInventoryWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for branch warehouses (storage locations). Enforces a single default
 * warehouse per branch: setting one default clears the others.
 */
final class WarehouseService
{
    use GuardsInventoryWrites;

    /** @return Collection<int, Warehouse> */
    public function list(InventoryContext $context): Collection
    {
        return Warehouse::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(InventoryContext $context, array $data): Warehouse
    {
        return DB::transaction(function () use ($context, $data): Warehouse {
            $this->assertBranchUnique(Warehouse::class, $context, 'code', (string) $data['code'], null);
            $warehouse = Warehouse::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'] ?? 'store',
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_sellable_source' => (bool) ($data['is_sellable_source'] ?? true),
                'status' => $data['status'] ?? 'active',
                'lock_version' => 0,
            ]);
            if ($warehouse->is_default) {
                $this->clearOtherDefaults($context, $warehouse->id);
            }
            $this->audit($context, 'warehouse', $warehouse->id, 'created');

            return $warehouse;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(InventoryContext $context, string $id, int $version, array $data): Warehouse
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Warehouse {
            $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
                ?? throw InventoryException::notFound('The warehouse was not found.');
            $this->assertVersion($warehouse->lock_version, $version);
            $warehouse->fill(array_intersect_key($data, array_flip([
                'name', 'type', 'is_default', 'is_sellable_source', 'status',
            ])));
            $warehouse->lock_version++;
            $warehouse->save();
            if ($warehouse->is_default) {
                $this->clearOtherDefaults($context, $warehouse->id);
            }
            $this->audit($context, 'warehouse', $warehouse->id, 'updated');

            return $warehouse->refresh();
        }, 3);
    }

    public function resolveDefault(InventoryContext $context): Warehouse
    {
        return Warehouse::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('status', 'active')
            ->orderByDesc('is_default')->orderBy('created_at')->first()
            ?? throw InventoryException::invalidState('No active warehouse is configured for this branch.');
    }

    private function clearOtherDefaults(InventoryContext $context, string $keepId): void
    {
        Warehouse::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKeyNot($keepId)
            ->where('is_default', true)->update(['is_default' => false]);
    }
}
