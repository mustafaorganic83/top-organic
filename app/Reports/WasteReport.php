<?php

namespace App\Reports;

use App\Models\Waste;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WasteReport extends BaseReport
{
    public function key(): string { return 'waste'; }
    public function name(): string { return 'Waste'; }

    protected function columns(): array
    {
        return [ 'occurred_at' => 'Date', 'warehouse_id' => 'Warehouse', 'department_id' => 'Department', 'item_id' => 'Item', 'qty' => 'Qty', 'pct' => 'Pct' ];
    }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $q = Waste::query()->select(['occurred_at','warehouse_id','department_id','item_id','qty','pct']);
        if ($req->groupBy) {
            $group = $req->groupBy;
            $q = Waste::query()->select([DB::raw("$group as __group"), DB::raw('SUM(qty) as qty'), DB::raw('AVG(pct) as pct')]);
            $q->groupBy($group);
        }
        return $q;
    }

    protected function applyFilters(\Illuminate\Database\Eloquent\Builder $q, ReportRequest $req): void
    {
        if ($req->branchId()) $q->where('branch_id', $req->branchId());
        if ($req->warehouseId()) $q->where('warehouse_id', $req->warehouseId());
        if ($req->departmentId()) $q->where('department_id', $req->departmentId());
        if ($req->dateFrom()) $q->where('occurred_at', '>=', $req->dateFrom());
        if ($req->dateTo()) $q->where('occurred_at', '<=', $req->dateTo());
    }
}
