<?php

namespace App\Reports;

use App\Reports\Contracts\Report;
use App\Reports\DTOs\ReportRequest;
use App\Reports\DTOs\ReportResult;
use Illuminate\Database\Eloquent\Builder;

abstract class BaseReport implements Report
{
    abstract protected function baseQuery(ReportRequest $req): Builder; // rows

    /** Columns metadata (key=>label). */
    protected function columns(): array { return []; }

    /** Map DB row/stdClass to array. */
    protected function mapRow($row): array { return (array)$row; }

    /** Optional totals accumulator. */
    protected function totals(array $rows): array { return []; }

    /** Reports may override to apply filters appropriate to their schema. */
    protected function applyFilters(Builder $q, ReportRequest $req): void { /* no-op by default */ }

    public function run(ReportRequest $req): ReportResult
    {
        $q = $this->baseQuery($req);
        $this->applyFilters($q, $req);
        if ($req->groupBy) {
            $q->addSelect($req->groupBy.' as __group');
            $q->groupBy($req->groupBy);
        }
        $rows = $q->get()->map(fn($r)=>$this->mapRow($r))->all();
        return new ReportResult($this->columns(), $rows, $this->totals($rows), [
            'grouped_by' => $req->groupBy,
            'drill_key' => $req->drillKey,
        ]);
    }
}
