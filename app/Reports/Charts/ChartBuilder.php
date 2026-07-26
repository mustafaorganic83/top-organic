<?php

namespace App\Reports\Charts;

use App\Reports\DTOs\ReportResult;

class ChartBuilder
{
    /** Build a basic Chart.js config from a tabular report. */
    public static function line(ReportResult $r, string $xKey, string $yKey, string $label = 'Series'): array
    {
        $labels = array_map(fn($row)=>$row[$xKey] ?? '', $r->rows);
        $data = array_map(fn($row)=> (float)($row[$yKey] ?? 0), $r->rows);
        return [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => [[ 'label' => $label, 'data' => $data ]],
            ],
            'options' => [ 'responsive' => true ],
        ];
    }
}
