<?php

declare(strict_types=1);

namespace App\Modules\Menu\Reports;

use Mpdf\Mpdf;
use Mpdf\MpdfException;

/**
 * Renders a report table to PDF with mPDF. mPDF is used rather than a DomPDF
 * variant because it shapes and bidi-orders Arabic text correctly, which the
 * kitchen's printed cost sheets depend on.
 */
final class PdfReportWriter
{
    /**
     * @param  array<string, string>  $meta  label => value shown under the title
     *
     * @throws MpdfException
     */
    public function render(ReportTable $table, array $meta = []): string
    {
        $rtl = in_array(app()->getLocale(), config('region.rtl_locales', ['ar']), true);

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => storage_path('app/mpdf'),
            'default_font' => 'dejavusans',
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);

        if ($rtl) {
            $pdf->SetDirectionality('rtl');
        }

        $pdf->WriteHTML($this->html($table, $meta, $rtl));

        return (string) $pdf->Output('', 'S');
    }

    /** @param array<string, string> $meta */
    private function html(ReportTable $table, array $meta, bool $rtl): string
    {
        $align = $rtl ? 'right' : 'left';
        $numeric = $rtl ? 'left' : 'right';

        $head = '';
        foreach ($table->headings as $heading) {
            $head .= '<th>'.e($heading).'</th>';
        }

        $body = '';
        foreach ($table->rows as $row) {
            $body .= '<tr>';
            foreach (array_values($row) as $column => $cell) {
                $style = $table->isNumeric($column) ? ' class="num"' : '';
                $body .= '<td'.$style.'>'.e($cell).'</td>';
            }
            $body .= '</tr>';
        }

        if ($body === '') {
            $body = '<tr><td colspan="'.count($table->headings).'" class="empty">'
                .e(__('menu.reports.no_rows')).'</td></tr>';
        }

        $metaHtml = '';
        foreach ($meta as $label => $value) {
            $metaHtml .= '<span>'.e($label).': '.e($value).'</span> ';
        }

        return <<<HTML
        <style>
            body { font-family: dejavusans, sans-serif; font-size: 9pt; }
            h1 { font-size: 14pt; margin: 0 0 4px; text-align: {$align}; }
            .meta { font-size: 8pt; color: #555; margin-bottom: 10px; text-align: {$align}; }
            .meta span { margin-{$align}: 0; margin-inline-end: 12px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 0.4pt solid #cbd5e1; padding: 4pt 5pt; text-align: {$align}; }
            th { background: #f1f5f9; font-weight: bold; }
            td.num { text-align: {$numeric}; direction: ltr; }
            td.empty { text-align: center; color: #64748b; padding: 18pt; }
        </style>
        <h1>{$this->escaped($table->title)}</h1>
        <div class="meta">{$metaHtml}</div>
        <table><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>
        HTML;
    }

    private function escaped(string $value): string
    {
        return e($value);
    }
}
