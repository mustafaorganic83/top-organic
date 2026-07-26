<?php

namespace App\Reports;

use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class MenuEngineeringReport extends BaseReport
{
    public function key(): string { return 'menu_engineering'; }
    public function name(): string { return 'Menu Engineering'; }

    protected function columns(): array
    { return [ 'variant_id'=>'Variant','qty'=>'Qty','revenue'=>'Revenue','cm'=>'Contribution','popularity'=>'Popularity','category'=>'Category' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $ri = DB::table('recipes as r')->leftJoin('recipe_versions as v','v.id','=','r.active_version_id')
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
        $revenue=(int)$r->revenue_amount/100.0; $qty=(float)$r->qty; $cogs=(int)$r->cogs_amount/100.0; $cm=$revenue-$cogs;
        // Popularity & Category need cohort averages – compute later in totals
        return ['variant_id'=>(string)$r->variant_id,'qty'=>$qty,'revenue'=>$revenue,'cm'=>$cm,'popularity'=>0,'category'=>''];
    }

    protected function totals(array $rows): array
    {
        $totalQty = array_sum(array_map(fn($r)=>$r['qty'],$rows)) ?: 1.0;
        $avgQty = $totalQty / (count($rows) ?: 1);
        $avgCM = array_sum(array_map(fn($r)=>$r['cm'],$rows)) / (count($rows) ?: 1);
        $with = array_map(function($r) use($avgQty,$avgCM){
            $pop = $r['qty'] >= $avgQty ? 'high' : 'low';
            $prof = $r['cm'] >= $avgCM ? 'high' : 'low';
            $cat = ($pop==='high'&&$prof==='high')?'Star':(($pop==='high')?'Plowhorse':(($prof==='high')?'Puzzle':'Dog'));
            return $r + ['popularity'=>$pop,'category'=>$cat];
        }, $rows);
        return ['rows'=>$with,'avg_qty'=>$avgQty,'avg_cm'=>$avgCM];
    }
}
