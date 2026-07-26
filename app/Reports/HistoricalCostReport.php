<?php

namespace App\Reports;

use App\Models\CostSnapshot;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class HistoricalCostReport extends BaseReport
{
    public function key(): string { return 'historical_cost'; }
    public function name(): string { return 'Historical Cost'; }

    protected function columns(): array
    { return [ 'entity_type' => 'Type', 'entity_id' => 'ID', 'as_of_date' => 'Date', 'method' => 'Method', 'unit_cost' => 'Unit Cost' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        $q = CostSnapshot::query()->select(['entity_type','entity_id','as_of_date','method','unit_cost']);
        if (!empty($req->filters['entity_type'])) $q->where('entity_type', $req->filters['entity_type']);
        if (!empty($req->filters['entity_id'])) $q->where('entity_id', $req->filters['entity_id']);
        return $q;
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->dateFrom()) $q->where('as_of_date', '>=', $req->dateFrom());
        if ($req->dateTo()) $q->where('as_of_date', '<=', $req->dateTo());
    }
}
