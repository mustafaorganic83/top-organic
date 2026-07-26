<?php

namespace App\Reports\Export;

use App\Reports\DTOs\ReportResult;

interface ExporterInterface
{
    public function supports(string $format): bool; // csv, excel, pdf, chart
    public function export(ReportResult $result, array $options = []): string; // returns string payload (csv/html/json)
}
