<?php

namespace App\Reports;

use App\Models\Waste;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class DepartmentCostReport extends BaseReport
{
    public function key(): string { return 'department_cost'; }
    public function name(): string { return 'Department Cost'; }

    protected function columns(): array
    { return [ 'department_id'=>'Department','waste_qty'=>'Waste Qty','waste_pct_avg'=>'Avg Waste %' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $q = Waste::query()->select([
            'department_id', DB::raw('SUM(qty) as waste_qty'), DB::raw('AVG(pct) as waste_pct_avg')
        ])->groupBy('department_id');
        return $q;
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->branchId()) $q->where('branch_id', $req->branchId());
        if ($req->dateFrom()) $q->where('occurred_at', '>=', $req->dateFrom());
        if ($req->dateTo()) $q->where('occurred_at', '<=', $req->dateTo());
    }
}
