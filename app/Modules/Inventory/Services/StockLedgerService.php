<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\InventoryMovement;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Modules\Inventory\Data\InventoryContext;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\Concerns\GuardsInventoryWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The core stock ledger. Owns the per-warehouse on-hand level, the append-only
 * movement ledger, and lot/batch tracking. Receipts create a batch and raise
 * the level, recomputing the moving-average cost. Issues draw batches down in
 * the stockable's configured order — FIFO (oldest received first) or FEFO
 * (soonest expiry first) — and value the deduction at each batch's own cost;
 * average-costed items skip batch draw and value at the level's moving average.
 * Every movement is keyed by a client operation id so a replayed offline
 * command is idempotent at the database layer.
 */
final class StockLedgerService
{
    use GuardsInventoryWrites;

    /** @return Collection<int, StockLevel> */
    public function levels(InventoryContext $context, ?string $warehouseId): Collection
    {
        return StockLevel::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get();
    }

    /** @return Collection<int, StockBatch> */
    public function batches(InventoryContext $context, ?string $warehouseId, ?string $stockableId): Collection
    {
        return StockBatch::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($stockableId !== null, fn ($q) => $q->where('stockable_id', $stockableId))
            ->orderBy('received_at')->get();
    }

    /** @return Collection<int, StockItem> */
    public function lowStock(InventoryContext $context): Collection
    {
        // Items whose branch on-hand across warehouses is at or below reorder.
        $items = StockItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('reorder_point', '>', 0)->get();
        $onHand = StockLevel::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('stockable_type', 'stock_item')
            ->selectRaw('stockable_id, SUM(quantity_on_hand) as qty')->groupBy('stockable_id')
            ->pluck('qty', 'stockable_id');

        return $items->filter(fn (StockItem $i) => (float) ($onHand[$i->id] ?? 0) <= (float) $i->reorder_point)->values();
    }

    /**
     * Receive stock into a warehouse: create a batch (lot/expiry aware) and
     * raise the level, recomputing the moving-average cost. Idempotent per
     * client operation id.
     *
     * @param  array<string, mixed>  $data
     */
    public function receive(InventoryContext $context, array $data): StockBatch
    {
        return DB::transaction(function () use ($context, $data): StockBatch {
            $operation = $data['client_operation_id'] ?? null;
            $type = (string) ($data['stockable_type'] ?? 'stock_item');
            $qty = (float) $data['quantity'];
            $unitCost = (int) ($data['unit_cost_amount'] ?? 0);

            $batch = StockBatch::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'warehouse_id' => $data['warehouse_id'],
                'stockable_type' => $type,
                'stockable_id' => $data['stockable_id'],
                'batch_number' => $data['batch_number'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'quantity_received' => $qty,
                'quantity_remaining' => $qty,
                'unit' => $data['unit'],
                'unit_cost_amount' => $unitCost,
                'currency' => $data['currency'] ?? null,
                'status' => 'open',
                'received_at' => now(),
                'lock_version' => 0,
            ]);

            $this->applyToLevel($context, (string) $data['warehouse_id'], $type,
                (string) $data['stockable_id'], $qty, $unitCost);
            $this->recordMovement($context, (string) $data['warehouse_id'], $type, (string) $data['stockable_id'],
                'receipt', $qty, (string) $data['unit'], $unitCost,
                'stock_batch', $batch->id, $operation);
            $this->audit($context, 'stock_batch', $batch->id, 'received',
                ['quantity' => $qty, 'unit_cost_amount' => $unitCost]);

            return $batch;
        }, 3);
    }

    /**
     * Issue (deduct) stock from a warehouse for a reason (consumption, waste,
     * transfer_out, count_adjustment). Batch-tracked items draw lots in
     * FIFO/FEFO order and value at each lot's cost; others value at the level's
     * moving average. Returns the resulting movement rows. Idempotent per op.
     *
     * @return array<int, InventoryMovement>
     */
    public function issue(InventoryContext $context, string $warehouseId, string $type, string $stockableId, float $quantity, string $unit, string $reason, ?string $referenceType, ?string $referenceId, ?string $operation): array
    {
        return DB::transaction(fn (): array => $this->issueLocked(
            $context, $warehouseId, $type, $stockableId, $quantity, $unit, $reason,
            $referenceType, $referenceId, $operation), 3);
    }

    /** @return array<int, InventoryMovement> */
    private function issueLocked(InventoryContext $context, string $warehouseId, string $type, string $stockableId, float $quantity, string $unit, string $reason, ?string $referenceType, ?string $referenceId, ?string $operation): array
    {
        if ($operation !== null) {
            // Match the exact op (average-cost single row) or any batch-slice
            // suffixed op ("op:batchId") from a prior FIFO/FEFO draw.
            $existing = InventoryMovement::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('stockable_id', $stockableId)
                ->where('reason', $reason)
                ->where(fn ($q) => $q->where('client_operation_id', $operation)
                    ->orWhere('client_operation_id', 'like', $operation.':%'))
                ->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }
        }

        $method = $this->costingMethod($context, $type, $stockableId);
        $movements = [];
        if ($method === 'fifo' || $method === 'fefo') {
            $movements = $this->drawBatches($context, $warehouseId, $type, $stockableId,
                $quantity, $unit, $reason, $method, $referenceType, $referenceId, $operation);
        } else {
            $level = $this->lockedLevel($context, $warehouseId, $type, $stockableId);
            $unitCost = $level !== null ? (int) $level->average_cost_amount : 0;
            $movements[] = $this->recordMovement($context, $warehouseId, $type, $stockableId,
                $reason, -1 * $quantity, $unit, $unitCost, $referenceType, $referenceId, $operation);
            $this->applyToLevel($context, $warehouseId, $type, $stockableId, -1 * $quantity, $unitCost);
        }

        return $movements;
    }

    /**
     * Record a manual adjustment (positive or negative) valued at the moving
     * average, e.g. correcting a miscount outside a formal count session.
     *
     * @param  array<string, mixed>  $data
     */
    public function adjust(InventoryContext $context, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($context, $data): InventoryMovement {
            $type = (string) ($data['stockable_type'] ?? 'stock_item');
            $delta = (float) $data['quantity_delta'];
            $warehouseId = (string) $data['warehouse_id'];
            $reason = (string) ($data['reason'] ?? 'adjustment');
            if ($delta < 0 && ($reason === 'waste' || $reason === 'adjustment')) {
                $issued = $this->issue($context, $warehouseId, $type, (string) $data['stockable_id'],
                    abs($delta), (string) $data['unit'], $reason,
                    $data['reference_type'] ?? null, $data['reference_id'] ?? null,
                    $data['client_operation_id'] ?? null);
                $movement = $issued[0] ?? throw InventoryException::insufficientStock('No stock available to deduct.');
                $this->audit($context, 'stock_level', (string) $data['stockable_id'], $reason,
                    ['quantity_delta' => $delta]);

                return $movement;
            }

            $unitCost = (int) ($data['unit_cost_amount'] ?? 0);
            $movement = $this->recordMovement($context, $warehouseId, $type, (string) $data['stockable_id'],
                $reason, $delta, (string) $data['unit'], $unitCost,
                $data['reference_type'] ?? null, $data['reference_id'] ?? null,
                $data['client_operation_id'] ?? null);
            $this->applyToLevel($context, $warehouseId, $type, (string) $data['stockable_id'], $delta, $unitCost);
            $this->audit($context, 'stock_level', (string) $data['stockable_id'], $reason,
                ['quantity_delta' => $delta]);

            return $movement;
        }, 3);
    }

    /**
     * Draw open batches down in FIFO/FEFO order until the demand is met,
     * valuing each slice at that batch's own unit cost.
     *
     * @return array<int, InventoryMovement>
     */
    private function drawBatches(InventoryContext $context, string $warehouseId, string $type, string $stockableId, float $quantity, string $unit, string $reason, string $method, ?string $referenceType, ?string $referenceId, ?string $operation): array
    {
        $order = $method === 'fefo' ? 'expiry_date' : 'received_at';
        $batches = StockBatch::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('warehouse_id', $warehouseId)
            ->where('stockable_type', $type)->where('stockable_id', $stockableId)
            ->where('status', 'open')->where('quantity_remaining', '>', 0)
            ->orderByRaw("$order IS NULL")->orderBy($order)->orderBy('received_at')
            ->lockForUpdate()->get();

        $remaining = $quantity;
        $movements = [];
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $batch->quantity_remaining);
            $batch->quantity_remaining = (float) $batch->quantity_remaining - $take;
            $batch->status = (float) $batch->quantity_remaining <= 0 ? 'depleted' : 'open';
            $batch->lock_version++;
            $batch->save();
            // Each batch slice is one movement row; suffix the operation id with
            // the batch id so several slices of one issue stay unique under the
            // ledger's replay index (issue() already deduped up front).
            $sliceOp = $operation !== null ? $operation.':'.$batch->id : null;
            $movements[] = $this->recordMovement($context, $warehouseId, $type, $stockableId,
                $reason, -1 * $take, $unit, (int) $batch->unit_cost_amount,
                $referenceType, $referenceId, $sliceOp);
            $this->applyToLevel($context, $warehouseId, $type, $stockableId, -1 * $take, (int) $batch->unit_cost_amount);
            $remaining -= $take;
        }

        if ($remaining > 0.000001) {
            throw InventoryException::insufficientStock(
                'Not enough stock in the warehouse to satisfy the issue.',
                ['stockable_id' => $stockableId, 'short_by' => $remaining],
            );
        }

        return $movements;
    }

    private function costingMethod(InventoryContext $context, string $type, string $stockableId): string
    {
        if ($type !== 'stock_item') {
            return 'average';
        }
        $item = StockItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($stockableId)->first();

        return $item !== null && $item->is_batch_tracked ? (string) $item->costing_method : 'average';
    }

    private function lockedLevel(InventoryContext $context, string $warehouseId, string $type, string $stockableId): ?StockLevel
    {
        return StockLevel::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('warehouse_id', $warehouseId)
            ->where('stockable_type', $type)->where('stockable_id', $stockableId)
            ->lockForUpdate()->first();
    }

    /**
     * Move the on-hand level by a delta, recomputing the moving-average cost on
     * a positive delta (a receipt/addition). A negative delta leaves the
     * average unchanged, which is the standard moving-average convention.
     */
    private function applyToLevel(InventoryContext $context, string $warehouseId, string $type, string $stockableId, float $delta, int $unitCost): void
    {
        $level = $this->lockedLevel($context, $warehouseId, $type, $stockableId);
        if ($level === null) {
            StockLevel::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'warehouse_id' => $warehouseId,
                'stockable_type' => $type,
                'stockable_id' => $stockableId,
                'quantity_on_hand' => $delta,
                'reserved_quantity' => 0,
                'reorder_level' => 0,
                'average_cost_amount' => $delta > 0 ? $unitCost : 0,
                'lock_version' => 0,
            ]);

            return;
        }

        $current = (float) $level->quantity_on_hand;
        if ($delta > 0) {
            $newQty = $current + $delta;
            $blended = $newQty > 0
                ? (int) round((($current * $level->average_cost_amount) + ($delta * $unitCost)) / $newQty)
                : $unitCost;
            $level->average_cost_amount = $blended;
        }
        $level->quantity_on_hand = $current + $delta;
        $level->lock_version++;
        $level->save();
    }

    private function recordMovement(InventoryContext $context, string $warehouseId, string $type, string $stockableId, string $reason, float $delta, string $unit, int $unitCost, ?string $referenceType, ?string $referenceId, ?string $operation): InventoryMovement
    {
        return InventoryMovement::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'branch_id' => $context->branchId,
            'stockable_type' => $type,
            'stockable_id' => $stockableId,
            'reason' => $reason,
            'quantity_delta' => $delta,
            'unit' => $unit,
            'unit_cost_amount' => $unitCost,
            'reference_type' => $referenceType ?? 'warehouse',
            'reference_id' => $referenceId ?? $warehouseId,
            'client_operation_id' => $operation,
            'actor_id' => $context->userId,
            'device_id' => $context->deviceId,
            'occurred_at' => now(),
        ]);
    }
}
