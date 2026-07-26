<?php

namespace App\Reports;

use App\Models\CostSnapshot;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class IngredientCostReport extends BaseReport
{
    public function key(): string { return 'ingredient_cost'; }
    public function name(): string { return 'Ingredient Cost'; }

    protected function columns(): array
    { return [ 'item_id' => 'Item', 'as_of_date' => 'Date', 'method' => 'Method', 'unit_cost' => 'Unit Cost' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        return CostSnapshot::query()
            ->where('entity_type', 'ITEM')
            ->select(['entity_id as item_id', 'as_of_date', 'method', 'unit_cost']);
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->dateFrom()) $q->where('as_of_date','>=',$req->dateFrom());
        if ($req->dateTo()) $q->where('as_of_date','<=',$req->dateTo());
        if ($req->itemId()) $q->where('entity_id',$req->itemId());
    }

    protected function mapRow($r): array
    {
        return [
            'item_id' => (string)$r->item_id,
            'as_of_date' => (string)$r->as_of_date,
            'method' => (string)$r->method,
            'unit_cost' => (float)$r->unit_cost,
        ];
    }
}
