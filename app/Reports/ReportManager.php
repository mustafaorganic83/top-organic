<?php

namespace App\Reports;

use App\Reports\Contracts\Report;
use App\Reports\DTOs\ReportRequest;
use App\Reports\DTOs\ReportResult;
use InvalidArgumentException;

class ReportManager
{
    /** @var array<string,Report> */
    private array $reports = [];

    /** @param array<Report> $reports */
    public function __construct(array $reports = [])
    {
        foreach ($reports as $r) { $this->register($r); }
    }

    public function register(Report $report): void
    {
        $this->reports[$report->key()] = $report;
    }

    public function list(): array
    {
        return array_map(fn($r)=>['key'=>$r->key(),'name'=>$r->name()], array_values($this->reports));
    }

    public function run(string $key, ReportRequest $req): ReportResult
    {
        $r = $this->reports[$key] ?? null;
        if (!$r) throw new InvalidArgumentException("Unknown report: $key");
        return $r->run($req);
    }
}
