<?php

namespace App\Reports\Contracts;

use App\Reports\DTOs\ReportRequest;
use App\Reports\DTOs\ReportResult;

interface Report
{
    public function key(): string; // machine key, e.g. recipe_cost
    public function name(): string; // human name

    /** Execute the report and return a normalized result. */
    public function run(ReportRequest $request): ReportResult;
}
