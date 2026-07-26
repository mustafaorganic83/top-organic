<?php

namespace App\Reports;

use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class ProfitabilityReport extends BaseReport
{
    public function key(): string { return 'profitability'; }
    public function name(): string { return 'Profitability'; }

    protected function columns(): array
    { return [ 'variant_id'=>'Variant','revenue'=>'Revenue','qty'=>'Qty','cogs'=>'COGS','gross_profit'=>'Gross Profit','avg_food_cost_pct'=>'Avg Food Cost %' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        // Aggregate sales from order_items and approximate COGS from recipe_cost_amount
        $ri = DB::table('recipes as r')
            ->leftJoin('recipe_versions as v','v.id','=','r.active_version_id')
            ->select('r.owner_id as variant_id', DB::raw('COALESCE(v.recipe_cost_amount,0) as unit_cost_amount'));

        $q = OrderItem::query()->from('order_items as oi')
            ->leftJoinSub($ri,'rv','rv.variant_id','=','oi.product_variant_id')
            ->select([
                'oi.product_variant_id as variant_id',
                DB::raw('SUM(oi.net_amount) as revenue_amount'),
                DB::raw('SUM(oi.quantity) as qty'),
                DB::raw('SUM(oi.quantity * COALESCE(rv.unit_cost_amount,0)) as cogs_amount'),
            ])
            ->groupBy('oi.product_variant_id');
        return $q;
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->branchId()) $q->where('oi.branch_id', $req->branchId());
        if ($req->dateFrom()) $q->where('oi.created_at', '>=', $req->dateFrom());
        if ($req->dateTo()) $q->where('oi.created_at', '<=', $req->dateTo());
    }

    protected function mapRow($r): array
    {
        $rev = (int)$r->revenue_amount; $cogs = (int)$r->cogs_amount; $qty=(float)$r->qty;
        $revF=$rev/100.0; $cogsF=$cogs/100.0; $avg=$qty>0?($cogsF/$qty):0.0; $priceAvg=$qty>0?($revF/$qty):0.0;
        return [
            'variant_id'=>(string)$r->variant_id,
            'revenue'=>$revF,
            'qty'=>$qty,
            'cogs'=>$cogsF,
            'gross_profit'=>$revF-$cogsF,
            'avg_food_cost_pct'=>$priceAvg>0?($avg/$priceAvg):0.0,
        ];
    }
}
