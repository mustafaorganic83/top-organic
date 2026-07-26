<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class DashboardService
{
    /** @param array<string,mixed> $filters */
    public function summary(array $filters): array
    {
        $revenue = (int) $this->safe(fn () => $this->revenueAmount($filters));
        $cogs = (int) $this->safe(fn () => $this->cogsAmount($filters));
        $waste = (int) $this->safe(fn () => $this->wasteValue($filters));
        $purchases = (int) $this->safe(fn () => $this->purchaseValue($filters));
        $inv = (int) $this->safe(fn () => $this->inventoryValue($filters));
        $foodCostPct = $revenue > 0 ? ($cogs / $revenue) : 0.0;
        $wastePct = $purchases > 0 ? ($waste / $purchases) : 0.0;
        return [
            'food_cost_pct' => round($foodCostPct, 6),
            'gross_profit' => ($revenue - $cogs) / 100.0,
            'waste_pct' => round($wastePct, 6),
            'inventory_value' => $inv / 100.0,
        ];
    }

    /** @param array<string,mixed> $filters */
    public function topIngredients(array $filters, int $limit = 5): array
    {
        return $this->safe(function () use ($filters, $limit) {
        $q = DB::table('inventory_movements as im')
            ->where('im.stockable_type', \App\Models\StockItem::class)
            ->where('im.quantity_delta', '<', 0)
            ->select('im.stockable_id as item_id', DB::raw('SUM(-im.quantity_delta * im.unit_cost_amount) as issue_value'))
            ->groupBy('im.stockable_id')
            ->orderByDesc(DB::raw('SUM(-im.quantity_delta * im.unit_cost_amount)'));
        $this->applyTimeAndScope($q, $filters, 'im.occurred_at');
        return array_map(function ($r) { return ['item_id'=>(string)$r->item_id,'value'=>(int)$r->issue_value/100.0]; }, $q->limit($limit)->get()->all());
        }, []);
    }

    /** @param array<string,mixed> $filters */
    public function topRecipes(array $filters, int $limit = 5): array
    {
        return $this->safe(function () use ($filters, $limit) {
        $rv = DB::table('recipes as r')->leftJoin('recipe_versions as v','v.id','=','r.active_version_id')
            ->select('r.owner_id as variant_id', DB::raw('COALESCE(v.recipe_cost_amount,0) as unit_cost_amount'));
        $q = DB::table('order_items as oi')
            ->leftJoinSub($rv,'rv','rv.variant_id','=','oi.product_variant_id')
            ->select('oi.product_variant_id as variant_id', DB::raw('SUM(oi.quantity * COALESCE(rv.unit_cost_amount,0)) as cogs_amount'))
            ->groupBy('oi.product_variant_id')
            ->orderByDesc(DB::raw('SUM(oi.quantity * COALESCE(rv.unit_cost_amount,0))'));
        $this->applyTimeAndScope($q, $filters, 'oi.created_at', 'oi.branch_id');
        return array_map(function ($r) { return ['variant_id'=>(string)$r->variant_id,'cogs'=>(int)$r->cogs_amount/100.0]; }, $q->limit($limit)->get()->all());
        }, []);
    }

    /** Trend over time buckets for metrics: cost|waste|purchase|production */
    public function trend(string $metric, string $interval, array $filters): array
    {
        return $this->safe(function () use ($metric, $interval, $filters) {
        $metric = strtolower($metric);
        $interval = strtolower($interval);
        $bucket = $this->bucketRaw($interval, isset($filters['date_col'])?$filters['date_col']:'occurred_at');
        if (in_array($metric, ['waste','purchase','production','cost'])) {
            $col = 'occurred_at';
            $q = DB::table('inventory_movements as im')->select($bucket, DB::raw('SUM(val) as value'));
            $expr = 'CASE WHEN im.quantity_delta<0 THEN -im.quantity_delta*im.unit_cost_amount ELSE im.quantity_delta*im.unit_cost_amount END';
            if ($metric==='waste') { $q->addSelect(DB::raw("($expr) as val"))->where('im.reason','waste'); }
            elseif ($metric==='purchase') { $q->addSelect(DB::raw("($expr) as val"))->where('im.reason','purchase'); }
            elseif ($metric==='production') { $q->addSelect(DB::raw("($expr) as val"))->where('im.reason','production'); }
            else { // cost (COGS) approximated from issues excluding waste
                $q->addSelect(DB::raw("($expr) as val"))->where('im.quantity_delta','<',0)->where('im.reason','!=','waste');
            }
            $q->groupBy('bucket');
            $this->applyTimeAndScope($q, $filters, 'im.'.$col);
            return $this->fetchSeries($q);
        }
        // default empty
        return [];
        }, []);
    }

    // Helpers
    /** @template T */
    private function safe(callable $fn, mixed $default = 0): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("DashboardService Error: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return $default;
        }
    }
    private function applyTimeAndScope($q, array $filters, string $dateCol, ?string $branchCol = null): void
    {
        if (!empty($filters['date_from'])) $q->where($dateCol, '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $q->where($dateCol, '<=', $filters['date_to']);
        $branchCol = $branchCol ?: 'im.branch_id';
        if (!empty($filters['branch_id'])) $q->where($branchCol, $filters['branch_id']);
        if (!empty($filters['warehouse_id'])) $q->where('im.warehouse_id', $filters['warehouse_id']);
    }

    private function bucketRaw(string $interval, string $col): \Illuminate\Database\Query\Expression
    {
        $interval = strtolower($interval);
        return match ($interval) {
            'daily' => DB::raw("DATE($col) as bucket"),
            'weekly' => DB::raw("DATE_FORMAT($col, '%x-W%v') as bucket"),
            'monthly' => DB::raw("DATE_FORMAT($col, '%Y-%m') as bucket"),
            'yearly' => DB::raw("DATE_FORMAT($col, '%Y') as bucket"),
            default => DB::raw("DATE($col) as bucket"),
        };
    }

    private function fetchSeries($q): array
    {
        return array_map(fn($r)=>['bucket'=>$r->bucket,'value'=>(int)$r->value/100.0], $q->orderBy('bucket')->get()->all());
    }

    private function revenueAmount(array $f): int
    {
        $q = DB::table('order_items as oi')->select(DB::raw('SUM(oi.net_amount) as rev'));
        $this->applyTimeAndScope($q, $f, 'oi.created_at', 'oi.branch_id');
        return (int) ($q->value('rev') ?? 0);
    }

    private function cogsAmount(array $f): int
    {
        $rv = DB::table('recipes as r')->leftJoin('recipe_versions as v','v.id','=','r.active_version_id')
            ->select('r.owner_id as variant_id', DB::raw('COALESCE(v.recipe_cost_amount,0) as unit_cost_amount'));
        $q = DB::table('order_items as oi')
            ->leftJoinSub($rv,'rv','rv.variant_id','=','oi.product_variant_id')
            ->select(DB::raw('SUM(oi.quantity * COALESCE(rv.unit_cost_amount,0)) as cogs'));
        $this->applyTimeAndScope($q, $f, 'oi.created_at', 'oi.branch_id');
        return (int) ($q->value('cogs') ?? 0);
    }

    private function wasteValue(array $f): int
    {
        $q = DB::table('inventory_movements as im')->where('im.reason','waste')
            ->select(DB::raw('SUM(CASE WHEN im.quantity_delta<0 THEN -im.quantity_delta*im.unit_cost_amount ELSE 0 END) as val'));
        $this->applyTimeAndScope($q, $f, 'im.occurred_at');
        return (int) ($q->value('val') ?? 0);
    }

    private function purchaseValue(array $f): int
    {
        $q = DB::table('inventory_movements as im')->where('im.reason','purchase')
            ->select(DB::raw('SUM(CASE WHEN im.quantity_delta>0 THEN im.quantity_delta*im.unit_cost_amount ELSE 0 END) as val'));
        $this->applyTimeAndScope($q, $f, 'im.occurred_at');
        return (int) ($q->value('val') ?? 0);
    }

    private function inventoryValue(array $f): int
    {
        $q = DB::table('stock_levels as sl')->select(DB::raw('SUM(sl.quantity_on_hand * sl.average_cost_amount) as val'));
        if (!empty($f['branch_id'])) $q->where('sl.branch_id', $f['branch_id']);
        if (!empty($f['warehouse_id'])) $q->where('sl.warehouse_id', $f['warehouse_id']);
        return (int) ($q->value('val') ?? 0);
    }
}
