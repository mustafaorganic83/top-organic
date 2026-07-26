<?php

namespace App\Reports;

use App\Models\CostSnapshot;
use App\Reports\DTOs\ReportRequest;
use Illuminate\Database\Eloquent\Builder;

class PreparedItemCostReport extends BaseReport
{
    public function key(): string { return 'prepared_item_cost'; }
    public function name(): string { return 'Prepared Item Cost'; }

    protected function columns(): array
    { return [ 'prepared_id' => 'Prepared Item', 'as_of_date' => 'Date', 'method' => 'Method', 'unit_cost' => 'Unit Cost' ]; }

    protected function baseQuery(ReportRequest $req): Builder
    {
        return CostSnapshot::query()
            ->where('entity_type', 'PREPARED')
            ->select(['entity_id as prepared_id', 'as_of_date', 'method', 'unit_cost']);
    }

    protected function applyFilters(Builder $q, ReportRequest $req): void
    {
        if ($req->dateFrom()) $q->where('as_of_date','>=',$req->dateFrom());
        if ($req->dateTo()) $q->where('as_of_date','<=',$req->dateTo());
    }
}
