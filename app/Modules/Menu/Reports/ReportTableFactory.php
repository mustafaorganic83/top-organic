<?php

declare(strict_types=1);

namespace App\Modules\Menu\Reports;

use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Services\DishCostReportService;
use App\Modules\Menu\Support\MoneyFormatter;

/**
 * Turns the raw report rows into display tables. Every money value is
 * formatted through MoneyFormatter so the region's decimal rules apply
 * consistently across the screen, the PDF, and the spreadsheet.
 */
final class ReportTableFactory
{
    public function __construct(private readonly DishCostReportService $reports) {}

    /** Build the table for one of the three report kinds. */
    public function make(MenuContext $context, string $kind, ?string $categoryId = null): ReportTable
    {
        return match ($kind) {
            'ingredient_cost' => $this->ingredientTable($context),
            'semi_finished_cost' => $this->semiFinishedTable($context),
            default => $this->dishTable($context, $categoryId),
        };
    }

    private function dishTable(MenuContext $context, ?string $categoryId): ReportTable
    {
        $rows = [];
        foreach ($this->reports->dishRows($context, $categoryId) as $row) {
            $rows[] = [
                (string) $row['sku'],
                (string) $row['dish'],
                (string) ($row['category'] ?? '—'),
                (string) $row['variant'],
                MoneyFormatter::amount($row['ingredient_cost'], $row['currency']),
                MoneyFormatter::amount($row['cost'], $row['currency']),
                MoneyFormatter::amount($row['sale_price'], $row['currency']),
                MoneyFormatter::amount($row['profit'], $row['currency']),
                MoneyFormatter::percent($row['food_cost_bps']),
            ];
        }

        return new ReportTable(
            __('menu.reports.dish_cost'),
            [
                __('menu.dishes.sku'),
                __('menu.dishes.name'),
                __('menu.dishes.category'),
                __('menu.recipe.variant'),
                __('menu.recipe.ingredient_cost'),
                __('menu.dishes.cost'),
                __('menu.dishes.sale_price'),
                __('menu.dishes.profit'),
                __('menu.dishes.food_cost'),
            ],
            $rows,
            [4 => true, 5 => true, 6 => true, 7 => true, 8 => true],
        );
    }

    private function ingredientTable(MenuContext $context): ReportTable
    {
        $rows = [];
        foreach ($this->reports->ingredientRows($context) as $row) {
            $rows[] = [
                (string) $row['sku'],
                (string) $row['name'],
                __('menu.ingredients.kind_'.$row['kind']),
                (string) $row['unit'],
                MoneyFormatter::money($row['unit_cost'], $row['currency']),
                MoneyFormatter::percent($row['waste_bps']),
            ];
        }

        return new ReportTable(
            __('menu.reports.ingredient_cost'),
            [
                __('menu.ingredients.sku'),
                __('menu.ingredients.name'),
                __('menu.ingredients.kind'),
                __('menu.ingredients.stock_unit'),
                __('menu.ingredients.unit_cost'),
                __('menu.ingredients.waste'),
            ],
            $rows,
            [4 => true, 5 => true],
        );
    }

    private function semiFinishedTable(MenuContext $context): ReportTable
    {
        $rows = [];
        foreach ($this->reports->semiFinishedRows($context) as $row) {
            $rows[] = [
                (string) $row['sku'],
                (string) $row['name'],
                (string) $row['yield_quantity'],
                (string) $row['yield_unit'],
                MoneyFormatter::amount($row['ingredient_cost'], $row['currency']),
                MoneyFormatter::money($row['unit_cost'], $row['currency']),
            ];
        }

        return new ReportTable(
            __('menu.reports.semi_finished_cost'),
            [
                __('menu.ingredients.sku'),
                __('menu.ingredients.name'),
                __('menu.ingredients.yield_quantity'),
                __('menu.ingredients.yield_unit'),
                __('menu.recipe.ingredient_cost'),
                __('menu.ingredients.unit_cost'),
            ],
            $rows,
            [2 => true, 4 => true, 5 => true],
        );
    }
}
