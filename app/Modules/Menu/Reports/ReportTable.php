<?php

declare(strict_types=1);

namespace App\Modules\Menu\Reports;

/**
 * A rendered report: a title, column headings, and rows of already-formatted
 * cell strings. Formatting happens once, here, so the PDF and the spreadsheet
 * cannot drift apart in how they display money or percentages.
 */
final class ReportTable
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, bool>  $numericColumns  column index => render as a number
     */
    public function __construct(
        public readonly string $title,
        public readonly array $headings,
        public readonly array $rows,
        public readonly array $numericColumns = [],
    ) {}

    public function isNumeric(int $column): bool
    {
        return $this->numericColumns[$column] ?? false;
    }
}
