<?php

namespace App\Reports\Export;

use App\Reports\DTOs\ReportResult;

/**
 * Placeholder. Falls back to CSV content; can be swapped to Maatwebsite/Excel if approved.
 */
class ExcelExporter implements ExporterInterface
{
    public function supports(string $format): bool { return strtolower($format) === 'excel'; }

    public function export(ReportResult $r, array $options = []): string
    {
        return (new CsvExporter())->export($r, $options);
    }
}
