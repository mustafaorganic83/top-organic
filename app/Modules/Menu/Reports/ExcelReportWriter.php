<?php

declare(strict_types=1);

namespace App\Modules\Menu\Reports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Renders a report table to XLSX. Cells are written as text because they are
 * already formatted for the region; the sheet is a faithful copy of what the
 * screen and the PDF show rather than a second source of truth.
 */
final class ExcelReportWriter
{
    /** @param array<string, string> $meta */
    public function render(ReportTable $table, array $meta = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($table->title, 0, 31));
        $sheet->setRightToLeft(in_array(app()->getLocale(), config('region.rtl_locales', ['ar']), true));

        $columns = count($table->headings);
        $sheet->setCellValue([1, 1], $table->title);
        $sheet->mergeCells([1, 1, max($columns, 1), 1]);
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue([1, 2], $this->metaLine($meta));
        $sheet->mergeCells([1, 2, max($columns, 1), 2]);

        foreach ($table->headings as $index => $heading) {
            $sheet->setCellValue([$index + 1, 4], $heading);
        }

        $headerStyle = $sheet->getStyle([1, 4, max($columns, 1), 4]);
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');

        $row = 5;
        foreach ($table->rows as $line) {
            foreach (array_values($line) as $column => $cell) {
                $sheet->setCellValueExplicit([$column + 1, $row], $cell, DataType::TYPE_STRING);
                if ($table->isNumeric($column)) {
                    $sheet->getStyle([$column + 1, $row])->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }
            $row++;
        }

        for ($column = 1; $column <= max($columns, 1); $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return $this->toString($spreadsheet);
    }

    /** @param array<string, string> $meta */
    private function metaLine(array $meta): string
    {
        $parts = [];
        foreach ($meta as $label => $value) {
            $parts[] = $label.': '.$value;
        }

        return implode('   ', $parts);
    }

    private function toString(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $contents;
    }
}
