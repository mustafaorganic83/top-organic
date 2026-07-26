<?php

namespace App\Reports;

use App\Models\CostSnapshot;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class RecipeCostReport extends BaseReport
{
    public function key(): string { return 'recipe_cost'; }
    public function name(): string { return 'Recipe Cost'; }

    protected function columns(): array
    {
        return [ 'recipe_id' => 'Recipe', 'as_of_date' => 'Date', 'method' => 'Method', 'unit_cost' => 'Unit Cost' ];
    }

    protected function baseQuery(ReportRequest $req): Builder
    {
        return CostSnapshot::query()
            ->where('entity_type', 'RECIPE')
            ->select(['entity_id as recipe_id', 'as_of_date', 'method', 'unit_cost']);
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->dateFrom()) $q->where('as_of_date','>=',$req->dateFrom());
        if ($req->dateTo()) $q->where('as_of_date','<=',$req->dateTo());
    }

    protected function mapRow($r): array
    {
        return [
            'recipe_id' => (string)$r->recipe_id,
            'as_of_date' => (string)$r->as_of_date,
            'method' => (string)$r->method,
            'unit_cost' => (float)$r->unit_cost,
        ];
    }
}
