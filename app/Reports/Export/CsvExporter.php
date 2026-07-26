<?php

namespace App\Reports\Export;

use App\Reports\DTOs\ReportResult;

class CsvExporter implements ExporterInterface
{
    public function supports(string $format): bool { return in_array(strtolower($format), ['csv','excel']); }

    public function export(ReportResult $r, array $options = []): string
    {
        $rows = [];
        $keys = array_keys($r->columns);
        $labels = array_values($r->columns);
        $rows[] = implode(',', array_map(fn($v)=>str_replace([",","\n","\r"], ' ', (string)$v), $labels));
        foreach ($r->rows as $row) {
            $line = [];
            foreach ($keys as $k) { $line[] = isset($row[$k]) ? str_replace([",","\n","\r"], ' ', (string)$row[$k]) : ''; }
            $rows[] = implode(',', $line);
        }
        return implode("\n", $rows);
    }
}
