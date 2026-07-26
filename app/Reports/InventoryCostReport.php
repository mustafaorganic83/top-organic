<?php

namespace App\Reports;

use App\Models\InventoryMovement;
use App\Models\StockLevel;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InventoryCostReport extends BaseReport
{
    public function key(): string { return 'inventory_cost'; }
    public function name(): string { return 'Inventory Cost'; }

    protected function columns(): array
    { return [ 'warehouse_id' => 'Warehouse', 'stockable_type' => 'Type', 'stockable_id' => 'Item', 'onhand_qty' => 'On Hand', 'avg_cost' => 'Avg Cost', 'onhand_value' => 'On Hand Value' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        // Snapshot of on-hand value using StockLevel (per branch+warehouse)
        $q = StockLevel::query()->select([
            'warehouse_id','stockable_type','stockable_id',
            DB::raw('quantity_on_hand as onhand_qty'),
            DB::raw('average_cost_amount as avg_cost'),
            DB::raw('(quantity_on_hand * average_cost_amount) as onhand_value'),
        ]);
        return $q;
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->warehouseId()) $q->where('warehouse_id', $req->warehouseId());
        if ($req->branchId()) $q->where('branch_id', $req->branchId());
    }
}
