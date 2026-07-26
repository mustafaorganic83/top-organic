<?php

namespace App\Observers;

use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryCostingService;

class InventoryMovementObserver
{
    public function __construct(private InventoryCostingService $inventoryCosting)
    {
    }

    public function created(InventoryMovement $row): void
    {
        // Apply costing effects (unit_cost, levels, batches)
        $this->inventoryCosting->onMovementCreated($row);

        // Queue recalculation only for stock items (prepared handled by production observer)
        if ($row->stockable_type === \App\Models\StockItem::class && (float)$row->quantity_delta !== 0.0) {
            \App\Jobs\EnqueueRecalcForChangedNode::dispatch('ITEM', $row->stockable_id)->onQueue('recalc');
        }
    }
}
