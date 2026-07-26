<?php

namespace App\Reports;

use App\Models\OrderItem;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class TheoreticalVsActualReport extends BaseReport
{
    public function key(): string { return 'theoretical_vs_actual'; }
    public function name(): string { return 'Theoretical vs Actual Consumption'; }

    protected function columns(): array
    { return [ 'stock_item_id'=>'Item','theoretical_qty'=>'Theoretical Qty','actual_issue_qty'=>'Actual Issue Qty','variance_qty'=>'Variance Qty' ]; }

    // Override default runner to use Query Builder safely
    public function run(ReportRequest $req): \App\Reports\DTOs\ReportResult
    {
        $rc = DB::table('recipes as r')
            ->join('recipe_versions as v','v.id','=','r.active_version_id')
            ->join('recipe_components as c','c.recipe_version_id','=','v.id')
            ->where('c.component_type','stock_item')
            ->select('r.owner_id as variant_id','c.component_id as stock_item_id', DB::raw('SUM(c.quantity) as comp_qty'))
            ->groupBy('r.owner_id','c.component_id');

        $theoreticalQ = DB::table('order_items as oi')
            ->joinSub($rc,'rc','rc.variant_id','=','oi.product_variant_id')
            ->select('rc.stock_item_id', DB::raw('SUM(oi.quantity * rc.comp_qty) as theoretical_qty'))
            ->groupBy('rc.stock_item_id');
        if ($req->branchId()) $theoreticalQ->where('oi.branch_id', $req->branchId());
        if ($req->dateFrom()) $theoreticalQ->where('oi.created_at', '>=', $req->dateFrom());
        if ($req->dateTo()) $theoreticalQ->where('oi.created_at', '<=', $req->dateTo());

        $actualQ = DB::table('inventory_movements as im')
            ->where('im.stockable_type', \App\Models\StockItem::class)
            ->where('im.quantity_delta','<',0)
            ->select('im.stockable_id as stock_item_id', DB::raw('SUM(-im.quantity_delta) as actual_issue_qty'))
            ->groupBy('im.stockable_id');
        if ($req->branchId()) $actualQ->where('im.branch_id', $req->branchId());
        if ($req->warehouseId()) $actualQ->where('im.warehouse_id', $req->warehouseId());
        if ($req->dateFrom()) $actualQ->where('im.occurred_at', '>=', $req->dateFrom());
        if ($req->dateTo()) $actualQ->where('im.occurred_at', '<=', $req->dateTo());

        $rows = DB::query()->fromSub($theoreticalQ,'t')
            ->leftJoinSub($actualQ,'a','a.stock_item_id','=','t.stock_item_id')
            ->select('t.stock_item_id', 't.theoretical_qty', DB::raw('COALESCE(a.actual_issue_qty,0) as actual_issue_qty'))
            ->get()
            ->map(fn($r)=>$this->mapRow($r))
            ->all();

        return new \App\Reports\DTOs\ReportResult($this->columns(), $rows, [], []);
    }

    protected function mapRow($r): array
    {
        $t = (float)$r->theoretical_qty; $a = (float)$r->actual_issue_qty;
        return [ 'stock_item_id'=>(string)$r->stock_item_id, 'theoretical_qty'=>$t, 'actual_issue_qty'=>$a, 'variance_qty'=>$a - $t ];
    }
}
