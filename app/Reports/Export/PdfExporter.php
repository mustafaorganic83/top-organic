<?php

namespace App\Reports\Export;

use App\Reports\DTOs\ReportResult;

class PdfExporter implements ExporterInterface
{
    public function supports(string $format): bool { return strtolower($format) === 'pdf'; }

    public function export(ReportResult $r, array $options = []): string
    {
        $title = $options['title'] ?? 'Report';
        // If dompdf available via barryvdh/laravel-dompdf, produce a PDF binary
        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            /** @var \Barryvdh\DomPDF\PDF $pdf */
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.table', [
                'title' => $title,
                'columns' => $r->columns,
                'rows' => $r->rows,
                'totals' => $r->totals,
                'meta' => $r->meta,
            ]);
            return $pdf->output();
        }
        // Fallback to HTML if dompdf is not installed
        return view('reports.table', [
            'title' => $title,
            'columns' => $r->columns,
            'rows' => $r->rows,
            'totals' => $r->totals,
            'meta' => $r->meta,
        ])->render();
    }
}
