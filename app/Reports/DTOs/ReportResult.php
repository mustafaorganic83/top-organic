<?php

namespace App\Reports\DTOs;

class ReportResult
{
    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(
        public array $columns,
        public array $rows,
        public array $totals = [],
        public array $meta = [], // {grouped_by, drill_key, chart: {...}}
    ) {}
}
