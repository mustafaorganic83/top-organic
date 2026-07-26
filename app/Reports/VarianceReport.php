<?php

namespace App\Reports;

use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class VarianceReport extends BaseReport
{
    public function key(): string { return 'variance'; }
    public function name(): string { return 'Variance'; }

    protected function columns(): array
    { return [ 'stockable_id'=>'Item','issue_qty'=>'Issued Qty','receipt_qty'=>'Received Qty','net_qty'=>'Net Qty','issue_cost'=>'Issue Cost','receipt_cost'=>'Receipt Cost','net_cost'=>'Net Cost' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $q = InventoryMovement::query()->select([
            'stockable_id',
            DB::raw('SUM(CASE WHEN quantity_delta<0 THEN -quantity_delta ELSE 0 END) as issue_qty'),
            DB::raw('SUM(CASE WHEN quantity_delta>0 THEN quantity_delta ELSE 0 END) as receipt_qty'),
            DB::raw('SUM(quantity_delta) as net_qty'),
            DB::raw('SUM(CASE WHEN quantity_delta<0 THEN -quantity_delta*unit_cost_amount ELSE 0 END) as issue_cost'),
            DB::raw('SUM(CASE WHEN quantity_delta>0 THEN quantity_delta*unit_cost_amount ELSE 0 END) as receipt_cost'),
        ])->groupBy('stockable_id');
        return $q;
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->branchId()) $q->where('branch_id', $req->branchId());
        if ($req->warehouseId()) $q->where('warehouse_id', $req->warehouseId());
        if ($req->dateFrom()) $q->where('occurred_at', '>=', $req->dateFrom());
        if ($req->dateTo()) $q->where('occurred_at', '<=', $req->dateTo());
    }

    protected function mapRow($r): array
    {
        $ic=(float)$r->issue_cost/100.0; $rc=(float)$r->receipt_cost/100.0;
        return [
            'stockable_id'=>(string)$r->stockable_id,
            'issue_qty'=>(float)$r->issue_qty,
            'receipt_qty'=>(float)$r->receipt_qty,
            'net_qty'=>(float)$r->net_qty,
            'issue_cost'=>$ic,
            'receipt_cost'=>$rc,
            'net_cost'=>$rc-$ic,
        ];
    }
}
