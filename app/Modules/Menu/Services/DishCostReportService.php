<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\Product;
use App\Models\Recipe;
use App\Models\SemiFinishedProduct;
use App\Models\StockItem;
use App\Modules\Menu\Data\MenuContext;
use Illuminate\Support\Collection;

/**
 * Builds the three costing reports the kitchen works from, mirroring the
 * sheets of the costing workbook: plate cost against sale price, the raw
 * ingredient price list, and the prepared-item batch costs. Figures come from
 * the recipe versions the costing engine already froze, so a report never
 * re-derives money and can never disagree with what was published.
 */
final class DishCostReportService
{
    public function __construct(private readonly RecipeCostingService $costing) {}

    /**
     * Per-variant plate costing: cost, sale price, profit, and food-cost
     * ratio. Variants without a costed recipe are still listed, with null
     * figures, so a gap in the recipe library is visible rather than hidden.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dishRows(MenuContext $context, ?string $categoryId = null): array
    {
        $products = Product::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['category', 'variants'])
            ->orderBy('sort_order')->orderBy('name')->get();

        $recipes = $this->recipesByVariant($context);
        $rows = [];

        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $version = ($recipes[$variant->id] ?? null)?->activeVersion;
                $cost = $version?->recipe_cost_amount;
                $price = $this->costing->variantSalePrice($context, $variant->id);

                $rows[] = [
                    'sku' => $product->sku,
                    'dish' => $product->name,
                    'category' => $product->category?->name,
                    'variant' => $variant->name ?? $variant->code,
                    'yield_quantity' => $version?->yield_quantity,
                    'yield_unit' => $version?->yield_unit,
                    'currency' => $version?->currency ?? config('region.currency.primary'),
                    'ingredient_cost' => $version?->ingredient_cost_amount,
                    'cost' => $cost,
                    'sale_price' => $price,
                    'profit' => $cost === null || $price === null ? null : $price - $cost,
                    'food_cost_bps' => $cost === null ? null : $this->costing->foodCostBps($cost, $price),
                ];
            }
        }

        return $rows;
    }

    /**
     * The raw ingredient price list — the "last purchase price" sheet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ingredientRows(MenuContext $context): array
    {
        return StockItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('status', 'active')->orderBy('name')->get()
            ->map(fn (StockItem $item): array => [
                'sku' => $item->sku,
                'name' => $item->name,
                'kind' => $item->kind,
                'unit' => $item->stock_unit,
                'currency' => $item->currency,
                'unit_cost' => $item->unit_cost_amount,
                'waste_bps' => $item->default_waste_bps,
            ])->all();
    }

    /**
     * Prepared-item batch costs — the "prepared cost" sheet. Cost per yield
     * unit comes from the item's own active recipe version.
     *
     * @return array<int, array<string, mixed>>
     */
    public function semiFinishedRows(MenuContext $context): array
    {
        $items = SemiFinishedProduct::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('status', 'active')->orderBy('name')->get();

        $recipes = Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('owner_type', 'semi_finished_product')
            ->whereIn('owner_id', $items->pluck('id'))
            ->with('activeVersion')->get()->keyBy('owner_id');

        return $items->map(function (SemiFinishedProduct $item) use ($recipes): array {
            $version = $recipes->get($item->id)?->activeVersion;

            return [
                'sku' => $item->sku,
                'name' => $item->name,
                'yield_quantity' => $item->yield_quantity,
                'yield_unit' => $item->yield_unit,
                'currency' => $version?->currency ?? config('region.currency.primary'),
                'ingredient_cost' => $version?->ingredient_cost_amount,
                'unit_cost' => $version?->recipe_cost_amount,
            ];
        })->all();
    }

    /**
     * Active recipes for this tenant's product variants, keyed by variant.
     *
     * @return Collection<string, Recipe>
     */
    private function recipesByVariant(MenuContext $context): Collection
    {
        return Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('owner_type', 'product_variant')
            ->with('activeVersion')->get()->keyBy('owner_id');
    }
}
