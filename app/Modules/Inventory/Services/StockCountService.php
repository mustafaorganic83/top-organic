<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockLevel;
use App\Modules\Inventory\Data\InventoryContext;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\Concerns\GuardsInventoryWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Physical and cycle counts. Opening a count freezes the expected on-hand
 * snapshot per line (whole warehouse for a physical count, a supplied subset
 * for a cycle count). Recording counted quantities computes variances; posting
 * writes an adjustment movement for every non-zero variance and reconciles the
 * on-hand level to the counted figure.
 */
final class StockCountService
{
    use GuardsInventoryWrites;

    public function __construct(private readonly StockLedgerService $ledger) {}

    /** @return Collection<int, StockCount> */
    public function list(InventoryContext $context): Collection
    {
        return StockCount::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->with('items')->orderByDesc('created_at')->get();
    }

    public function show(InventoryContext $context, string $id): StockCount
    {
        return StockCount::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->with('items')->first()
            ?? throw InventoryException::notFound('The count was not found.');
    }

    /** @param array<string, mixed> $data */
    public function open(InventoryContext $context, array $data): StockCount
    {
        return DB::transaction(function () use ($context, $data): StockCount {
            $this->assertBranchUnique(StockCount::class, $context, 'reference', (string) $data['reference'], null);
            $type = (string) ($data['type'] ?? 'physical');
            $count = StockCount::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'warehouse_id' => $data['warehouse_id'],
                'reference' => $data['reference'],
                'type' => $type,
                'status' => 'counting',
                'notes' => $data['notes'] ?? null,
                'counted_by' => $context->userId,
                'lock_version' => 0,
            ]);

            $levels = $this->snapshotLevels($context, (string) $data['warehouse_id'],
                $type === 'cycle' ? ($data['stockable_ids'] ?? []) : null);
            foreach ($levels as $level) {
                StockCountItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'stock_count_id' => $count->id,
                    'stockable_type' => $level->stockable_type,
                    'stockable_id' => $level->stockable_id,
                    'expected_quantity' => $level->quantity_on_hand,
                    'unit' => 'unit',
                ]);
            }
            $this->audit($context, 'stock_count', $count->id, 'opened', ['type' => $type]);

            return $count->load('items');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function record(InventoryContext $context, string $id, int $version, array $data): StockCount
    {
        return DB::transaction(function () use ($context, $id, $version, $data): StockCount {
            $count = $this->lockCount($context, $id);
            $this->assertVersion($count->lock_version, $version);
            if ($count->status !== 'counting') {
                throw InventoryException::invalidState('Only a counting session accepts entries.');
            }
            $entries = collect($data['counts'])->keyBy('stockable_id');
            foreach ($count->items as $item) {
                if (! $entries->has($item->stockable_id)) {
                    continue;
                }
                $counted = (float) $entries[$item->stockable_id]['counted_quantity'];
                $item->counted_quantity = $counted;
                $item->variance_quantity = $counted - (float) $item->expected_quantity;
                $item->save();
            }
            $count->lock_version++;
            $count->save();

            return $count->load('items');
        }, 3);
    }

    public function post(InventoryContext $context, string $id, int $version): StockCount
    {
        return DB::transaction(function () use ($context, $id, $version): StockCount {
            $count = $this->lockCount($context, $id);
            $this->assertVersion($count->lock_version, $version);
            if ($count->status !== 'counting') {
                throw InventoryException::invalidState('Only a counting session can be posted.');
            }
            foreach ($count->items as $item) {
                $variance = (float) $item->variance_quantity;
                if ($item->counted_quantity === null || abs($variance) < 0.000001) {
                    continue;
                }
                $this->ledger->adjust($context, [
                    'warehouse_id' => $count->warehouse_id,
                    'stockable_type' => $item->stockable_type,
                    'stockable_id' => $item->stockable_id,
                    'reason' => 'count_adjustment',
                    'quantity_delta' => $variance,
                    'unit' => $item->unit,
                    'reference_type' => 'stock_count',
                    'reference_id' => $count->id,
                    'client_operation_id' => $count->id.':'.$item->id,
                ]);
            }
            $count->status = 'posted';
            $count->posted_by = $context->userId;
            $count->posted_at = now();
            $count->lock_version++;
            $count->save();
            $this->audit($context, 'stock_count', $count->id, 'posted');

            return $count->load('items');
        }, 3);
    }

    private function lockCount(InventoryContext $context, string $id): StockCount
    {
        return StockCount::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->with('items')->lockForUpdate()->first()
            ?? throw InventoryException::notFound('The count was not found.');
    }

    /**
     * @param  array<int, string>|null  $stockableIds
     * @return Collection<int, StockLevel>
     */
    private function snapshotLevels(InventoryContext $context, string $warehouseId, ?array $stockableIds): Collection
    {
        return StockLevel::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('warehouse_id', $warehouseId)
            ->when($stockableIds !== null && $stockableIds !== [], fn ($q) => $q->whereIn('stockable_id', $stockableIds))
            ->get();
    }
}
