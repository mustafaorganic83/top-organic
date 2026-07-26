<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class PriceIncreaseImpactReport extends BaseReport
{
    public function key(): string { return 'price_increase_impact'; }
    public function name(): string { return 'Price Increase Impact'; }

    protected function columns(): array
    { return [ 'recipe_id'=>'Recipe','estimated_delta'=>'Estimated Cost Delta' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $bps = (int)($req->filters['increase_bps'] ?? 100); // default +1%
        // Approximation: sum of recipe component line_cost_amount for stock_items * (bps/10000)
        $q = DB::table('recipe_versions as v')
            ->join('recipes as r','r.id','=','v.recipe_id')
            ->join('recipe_components as c','c.recipe_version_id','=','v.id')
            ->where('c.component_type','stock_item')
            ->select('r.id as recipe_id', DB::raw('SUM(c.line_cost_amount) * '.($bps/10000.0).' as estimated_delta_amount'))
            ->groupBy('r.id');
        return DB::query()->fromSub($q,'x')->select('recipe_id','estimated_delta_amount');
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if (!empty($req->filters['recipe_id'])) $q->where('recipe_id', $req->filters['recipe_id']);
    }

    protected function mapRow($r): array
    { return [ 'recipe_id'=>(string)$r->recipe_id, 'estimated_delta'=>(int)$r->estimated_delta_amount / 100.0 ]; }
}
