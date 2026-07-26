<?php

namespace App\Reports;

use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class YieldReport extends BaseReport
{
    public function key(): string { return 'yield'; }
    public function name(): string { return 'Yield'; }

    protected function columns(): array
    { return [ 'prepared_recipe_id'=>'Prepared','planned_qty'=>'Planned Qty','actual_qty'=>'Actual Qty','yield_pct'=>'Yield %' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $q = ProductionOrder::query()->select([
            'prepared_recipe_id',
            DB::raw('SUM(planned_qty) as planned_qty'),
            DB::raw('SUM(actual_qty) as actual_qty'),
        ])->whereNotNull('completed_at')->groupBy('prepared_recipe_id');
        return $q;
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->branchId()) $q->where('branch_id', $req->branchId());
        if ($req->warehouseId()) $q->where('warehouse_id', $req->warehouseId());
        if ($req->dateFrom()) $q->where('completed_at', '>=', $req->dateFrom());
        if ($req->dateTo()) $q->where('completed_at', '<=', $req->dateTo());
    }

    protected function mapRow($r): array
    {
        $p=(float)$r->planned_qty; $a=(float)$r->actual_qty; $pct = $p>0?($a/$p):0.0;
        return [ 'prepared_recipe_id'=>(string)$r->prepared_recipe_id, 'planned_qty'=>$p, 'actual_qty'=>$a, 'yield_pct'=>$pct ];
    }
}
