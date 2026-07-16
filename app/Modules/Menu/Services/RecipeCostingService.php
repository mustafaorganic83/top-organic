<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\PriceListItem;
use App\Models\RecipeVersion;
use App\Models\StockItem;
use App\Modules\Menu\Data\MenuContext;

/**
 * Deterministic recipe costing. Works in integer minor units and basis points
 * throughout so results are exact and reproducible:
 *
 *  - line cost   = round(unit_cost * qty * (1 + waste_bps/10000))
 *  - ingredient  = sum(line costs)
 *  - recipe cost = ingredient cost, scaled up by the version-level waste, then
 *                  divided by yield to get a per-portion cost
 *  - food cost % = recipe_cost / sale_price (in bps)
 *
 * Component unit costs are resolved from the stock item / semi-finished
 * product; a semi-finished component's cost is its own active recipe cost, so
 * nested BOMs cost correctly.
 */
final class RecipeCostingService
{
    private const BPS = 10000;

    /**
     * Cost every component line, returning the priced lines plus totals. Does
     * not persist — the RecipeService snapshots the result at publish time.
     *
     * @param  array<int, array<string, mixed>>  $components  raw draft lines
     * @return array{lines: array<int, array<string, mixed>>, ingredient_cost: int, recipe_cost: int, currency: ?string}
     */
    public function cost(MenuContext $context, array $components, float $yieldQuantity, int $wasteBps): array
    {
        $lines = [];
        $ingredientCost = 0;
        $currency = null;

        foreach ($components as $component) {
            $unitCost = $this->resolveUnitCost($context, (string) $component['component_type'], (string) $component['component_id']);
            $currency ??= $unitCost['currency'];
            $qty = (float) $component['quantity'];
            $lineWaste = (int) ($component['waste_bps'] ?? 0);
            $lineCost = $this->applyWaste((int) round($unitCost['amount'] * $qty), $lineWaste);
            $ingredientCost += $lineCost;
            $lines[] = [
                'component_type' => $component['component_type'],
                'component_id' => $component['component_id'],
                'quantity' => $component['quantity'],
                'unit' => $component['unit'],
                'waste_bps' => $lineWaste,
                'unit_cost_amount' => $unitCost['amount'],
                'line_cost_amount' => $lineCost,
                'sort_order' => $component['sort_order'] ?? 0,
            ];
        }

        $batchCost = $this->applyWaste($ingredientCost, $wasteBps);
        $yield = $yieldQuantity > 0 ? $yieldQuantity : 1.0;
        $recipeCost = (int) round($batchCost / $yield);

        return [
            'lines' => $lines,
            'ingredient_cost' => $ingredientCost,
            'recipe_cost' => $recipeCost,
            'currency' => $currency,
        ];
    }

    /**
     * Food-cost ratio in basis points for a per-portion recipe cost against a
     * sale price. Returns null when the sale price is unknown or zero.
     */
    public function foodCostBps(int $recipeCost, ?int $salePrice): ?int
    {
        if ($salePrice === null || $salePrice <= 0) {
            return null;
        }

        return (int) round($recipeCost * self::BPS / $salePrice);
    }

    /**
     * The active sale price of a product variant, resolved from any price list
     * item (lowest revision-agnostic). Null when the variant is unpriced.
     */
    public function variantSalePrice(MenuContext $context, string $variantId): ?int
    {
        $item = PriceListItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('product_variant_id', $variantId)->orderBy('amount')->first();

        return $item?->amount;
    }

    /** Scale an amount up by a waste ratio expressed in basis points. */
    private function applyWaste(int $amount, int $wasteBps): int
    {
        if ($wasteBps <= 0) {
            return $amount;
        }

        return (int) round($amount * (self::BPS + $wasteBps) / self::BPS);
    }

    /**
     * Resolve the per-unit cost (minor units) of a component. Semi-finished
     * products cost at their own active recipe cost so nested BOMs are exact.
     *
     * @return array{amount: int, currency: ?string}
     */
    private function resolveUnitCost(MenuContext $context, string $type, string $id): array
    {
        if ($type === 'semi_finished_product') {
            $version = RecipeVersion::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereHas('recipe', fn ($q) => $q->where('owner_type', 'semi_finished_product')->where('owner_id', $id))
                ->where('state', 'active')->first();

            return ['amount' => $version?->recipe_cost_amount ?? 0, 'currency' => $version?->currency];
        }

        $item = StockItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first();

        return ['amount' => $item?->unit_cost_amount ?? 0, 'currency' => $item?->currency];
    }
}
