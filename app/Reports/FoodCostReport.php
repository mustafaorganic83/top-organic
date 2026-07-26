<?php

namespace App\Reports;

use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class FoodCostReport extends BaseReport
{
    public function key(): string { return 'food_cost'; }
    public function name(): string { return 'Food Cost'; }

    protected function columns(): array
    { return [ 'variant_id'=>'Variant','sell_price'=>'Sell Price','unit_cost'=>'Unit Cost','food_cost_pct'=>'Food Cost %','gross_profit'=>'Gross Profit' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        // Join price list items with recipe active version snapshots (via recipes)
        $sub = DB::table('recipes as r')
            ->leftJoin('recipe_versions as v','v.id','=','r.active_version_id')
            ->select('r.owner_id as variant_id', DB::raw('COALESCE(v.recipe_cost_amount,0) as unit_cost_amount'));

        $q = PriceListItem::query()->from('price_list_items as pli')
            ->joinSub($sub,'rv','rv.variant_id','=','pli.product_variant_id')
            ->select([
                'pli.product_variant_id as variant_id',
                DB::raw('pli.amount as sell_price_amount'),
                DB::raw('rv.unit_cost_amount as unit_cost_amount'),
            ]);
        return $q;
    }

    protected function mapRow($r): array
    {
        $sell = (int)$r->sell_price_amount; $cost = (int)$r->unit_cost_amount;
        $sellF = $sell/100.0; $costF = $cost/100.0;
        $pct = $sell>0 ? ($costF / $sellF) : 0.0;
        return [
            'variant_id' => (string)$r->variant_id,
            'sell_price' => $sellF,
            'unit_cost' => $costF,
            'food_cost_pct' => $pct,
            'gross_profit' => $sellF - $costF,
        ];
    }
}
