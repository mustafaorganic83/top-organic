<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Services\Costing\CostingEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Applies cost valuation to inventory movements and maintains on-hand levels
 * and batches per branch+warehouse. Minor units (int) for money; qty decimal.
 */
class InventoryCostingService
{
    public function __construct(private CostingEngine $costing) {}

    /** Handle a freshly-created movement: set unit_cost_amount if needed, update levels and batches. */
    public function onMovementCreated(InventoryMovement $m): void
    {
        DB::transaction(function () use ($m): void {
            $qty = (float)$m->quantity_delta; if ($qty == 0.0) return;
            $branchId = (string)$m->branch_id;
            $tenantId = (string)$m->tenant_id;
            $warehouseId = $m->warehouse_id ?? null; // required for multi-warehouse
            if (!$warehouseId) { return; } // silently skip until caller provides warehouse

            // Ensure stock level row exists (per branch+warehouse+stockable)
            $level = $this->getOrCreateLevel($tenantId, $branchId, (string)$warehouseId, (string)$m->stockable_type, (string)$m->stockable_id);

            // Compute receipt/issue unit cost if not provided
            $unitAmount = (int)$m->unit_cost_amount;
            if ($qty > 0) {
                if ($unitAmount <= 0) {
                    $unitAmount = $this->unitCostForReceipt($m, $level);
                    $m->unit_cost_amount = $unitAmount; $m->saveQuietly();
                }
                // Create a batch for receipts and update moving average
                $this->createBatch($m, $unitAmount);
                $this->applyReceiptToLevel($level, $qty, $unitAmount);
            } else { // issue
                if ($unitAmount <= 0) {
                    $unitAmount = $this->unitCostForIssue($m, $level, -$qty);
                    $m->unit_cost_amount = $unitAmount; $m->saveQuietly();
                }
                $this->applyIssueToBatches($m, -$qty, $unitAmount);
                $this->applyIssueToLevel($level, -$qty);
            }
        });
    }

    private function getOrCreateLevel(string $tenantId, string $branchId, string $warehouseId, string $type, string $id): StockLevel
    {
        /** @var StockLevel|null $level */
        $level = StockLevel::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('warehouse_id', $warehouseId)
            ->where('stockable_type', $type)
            ->where('stockable_id', $id)
            ->lockForUpdate()->first();
        if (!$level) {
            $level = new StockLevel([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'stockable_type' => $type,
                'stockable_id' => $id,
                'quantity_on_hand' => 0,
                'reserved_quantity' => 0,
                'average_cost_amount' => 0,
            ]);
            $level->saveQuietly();
        }
        return $level;
    }

    private function applyReceiptToLevel(StockLevel $level, float $qty, int $unitAmount): void
    {
        $onhand = (float)$level->quantity_on_hand;
        $avg = (int)$level->average_cost_amount;
        $newOnhand = $onhand + $qty;
        $newAvg = $newOnhand > 0 ? (int) round(((($avg * $onhand) + ($unitAmount * $qty)) / $newOnhand)) : $unitAmount;
        $level->quantity_on_hand = $newOnhand;
        $level->average_cost_amount = $newAvg;
        $level->saveQuietly();
    }

    private function applyIssueToLevel(StockLevel $level, float $qty): void
    {
        $level->quantity_on_hand = max(0.0, (float)$level->quantity_on_hand - $qty);
        // Moving-average unchanged on issue
        $level->saveQuietly();
    }

    private function unitCostForReceipt(InventoryMovement $m, StockLevel $level): int
    {
        $reason = strtolower((string)$m->reason);
        $moneyDivisor = 100;
        if ($reason === MovementReason::PURCHASE && $m->reference_type === 'goods_receipt_item') {
            $row = \App\Models\GoodsReceiptItem::query()->find($m->reference_id, ['unit_price_amount']);
            if ($row) return (int)$row->unit_price_amount;
        }
        if ($m->stockable_type === StockItem::class) {
            $unit = $this->costing->ingredientUnitCost((int)$m->stockable_id, Carbon::parse($m->occurred_at), \App\Services\Costing\CostMethod::LAST_PURCHASE, []);
            return (int) round($unit * $moneyDivisor);
        }
        // For semi-finished production receipts, approximate by current recipe unit cost
        if ($reason === MovementReason::PRODUCTION && $m->stockable_type === \App\Models\SemiFinishedProduct::class) {
            $prepared = \App\Models\SemiFinishedProduct::query()->find($m->stockable_id);
            $recipe = $prepared?->recipe()->first();
            $active = $recipe?->activeVersion()->first() ?? $recipe?->versions()->latest('created_at')->first();
            if ($active) {
                $res = $this->costing->recipeCost($active, Carbon::parse($m->occurred_at), \App\Services\Costing\CostMethod::WEIGHTED_AVG, []);
                return (int) round(((float)$res['unit_cost']) * $moneyDivisor);
            }
        }
        // Transfer-in tries to copy cost from linked transfer item
        if ($reason === MovementReason::TRANSFER_IN && $m->reference_type === 'stock_transfer_item') {
            $ti = \App\Models\StockTransferItem::query()->find($m->reference_id, ['unit_cost_amount']);
            if ($ti && (int)$ti->unit_cost_amount > 0) return (int)$ti->unit_cost_amount;
        }
        // Fallback to moving average
        return (int)$level->average_cost_amount;
    }

    private function unitCostForIssue(InventoryMovement $m, StockLevel $level, float $qtyAbs): int
    {
        $method = 'average';
        if ($m->stockable_type === StockItem::class) {
            $item = StockItem::query()->find($m->stockable_id, ['costing_method','is_perishable']);
            $method = strtolower((string)($item?->costing_method ?? 'average'));
            // If perishable and configured FIFO, prefer FEFO draw order
            if (($method === 'fifo') && ($item?->is_perishable)) {
                $method = 'fefo';
            }
        }
        return match ($method) {
            'fifo' => $this->drawFromBatches($m, $qtyAbs, order: 'fifo'),
            'lifo' => $this->drawFromBatches($m, $qtyAbs, order: 'lifo'),
            'fefo' => $this->drawFromBatches($m, $qtyAbs, order: 'fefo'),
            default => (int)$level->average_cost_amount,
        };
    }

    /** Drain qty from batches per order and return weighted unit cost (minor units). */
    private function drawFromBatches(InventoryMovement $m, float $qtyAbs, string $order = 'fifo'): int
    {
        $q = StockBatch::query()
            ->where('branch_id', $m->branch_id)
            ->where('warehouse_id', $m->warehouse_id)
            ->where('stockable_type', $m->stockable_type)
            ->where('stockable_id', $m->stockable_id)
            ->where('status', 'open');
        if ($order === 'lifo') {
            $q = $q->orderByDesc('received_at');
        } elseif ($order === 'fefo') {
            // Null expiry last, earliest expiry first
            $q = $q->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
                   ->orderBy('expiry_date');
        } else { // fifo
            $q = $q->orderBy('received_at');
        }
        $batches = $q->lockForUpdate()->get(['id','quantity_remaining','unit_cost_amount']);
        $toIssue = $qtyAbs; $totalCost = 0.0; $issued = 0.0;
        foreach ($batches as $b) {
            if ($toIssue <= 0) break;
            $avail = (float)$b->quantity_remaining; if ($avail <= 0) continue;
            $take = min($toIssue, $avail);
            $issued += $take; $toIssue -= $take; $totalCost += $take * ((int)$b->unit_cost_amount);
            // update batch
            $remain = $avail - $take;
            StockBatch::query()->where('id', $b->id)->update([
                'quantity_remaining' => $remain,
                'status' => $remain <= 0.0000001 ? 'depleted' : 'open',
            ]);
        }
        if ($issued <= 0) return (int)($m->unit_cost_amount ?? 0);
        return (int) round($totalCost / max(0.000001, $issued));
    }

    private function createBatch(InventoryMovement $m, int $unitAmount): void
    {
        // Create a simple open batch for the receipt
        StockBatch::query()->create([
            'tenant_id' => $m->tenant_id,
            'branch_id' => $m->branch_id,
            'warehouse_id' => $m->warehouse_id,
            'stockable_type' => $m->stockable_type,
            'stockable_id' => $m->stockable_id,
            'batch_number' => null,
            'lot_number' => null,
            'expiry_date' => null,
            'received_date' => Carbon::parse($m->occurred_at)->toDateString(),
            'quantity_received' => abs((float)$m->quantity_delta),
            'quantity_remaining' => abs((float)$m->quantity_delta),
            'unit' => (string)$m->unit,
            'unit_cost_amount' => $unitAmount,
            'currency' => null,
            'status' => 'open',
            'received_at' => Carbon::parse($m->occurred_at),
        ]);
    }

    private function applyIssueToBatches(InventoryMovement $m, float $qtyAbs, int $computedUnit): void
    {
        // If item uses average costing, do nothing to batches; drawFromBatches already handled FIFO/LIFO.
        $item = $m->stockable_type === StockItem::class ? StockItem::query()->find($m->stockable_id, ['costing_method']) : null;
        $method = strtolower((string)($item?->costing_method ?? 'average'));
        if ($method === 'average') return;
        // for fifo/lifo, drawFromBatches already decremented quantities when computing
    }
}
