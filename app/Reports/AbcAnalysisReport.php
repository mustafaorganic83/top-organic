<?php

namespace App\Reports;

use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class AbcAnalysisReport extends BaseReport
{
    public function key(): string { return 'abc_analysis'; }
    public function name(): string { return 'ABC Analysis'; }

    protected function columns(): array
    { return [ 'variant_id'=>'Variant','revenue'=>'Revenue','cumulative_pct'=>'Cumulative %','class'=>'Class' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $q = OrderItem::query()->from('order_items as oi')
            ->select(['product_variant_id as variant_id', DB::raw('SUM(net_amount) as revenue_amount')])
            ->groupBy('product_variant_id')
            ->orderByDesc(DB::raw('SUM(net_amount)'));
        return $q;
    }

    protected function mapRow($r): array { return ['variant_id'=>(string)$r->variant_id,'revenue'=>(int)$r->revenue_amount/100.0]; }

    protected function totals(array $rows): array
    {
        $sum = array_sum(array_map(fn($r)=>$r['revenue'], $rows)) ?: 1.0;
        $cum = 0.0; $out = [];
        foreach ($rows as $row) {
            $cum += $row['revenue'];
            $pct = $cum / $sum;
            $cls = $pct <= 0.8 ? 'A' : ($pct <= 0.95 ? 'B' : 'C');
            $out[] = $row + ['cumulative_pct'=>$pct,'class'=>$cls];
        }
        // not true totals; reuse meta rows for convenience
        return ['breakdown' => $out];
    }
}
