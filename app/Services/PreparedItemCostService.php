<?php

namespace App\Services;

use App\Contracts\Repositories\PurchasePriceRepositoryInterface;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use Illuminate\Support\Carbon;

class PreparedItemCostService
{
    public function __construct(private PurchasePriceRepositoryInterface $purchasePrices)
    {
    }

    /**
     * Compute total cost and unit cost for a recipe version at a given date.
     * Returns ['total_cost' => float, 'unit_cost' => float].
     */
    public function computeVersionCost(RecipeVersion $version, \DateTimeInterface|string $asOf, array $visited = []): array
    {
        $asOf = $asOf instanceof \DateTimeInterface ? $asOf : Carbon::parse($asOf);
        $key = 'rv:'.$version->getKey();
        if (isset($visited[$key])) { return ['total_cost' => 0.0, 'unit_cost' => 0.0]; }
        $visited[$key] = true;

        $subtotal = 0.0;
        /** @var RecipeComponent $line */
        foreach ($version->components()->get() as $line) {
            $qty = (float)$line->quantity;
            $w = (int)($line->waste_bps ?? 0);
            $p = max(0.0, min(0.9999, $w / 10000.0));
            $qtyEff = $qty / (1.0 - $p); // factor = 1/(1-p)

            $unitCost = 0.0;
            $type = (string)$line->component_type;
            if ($type === 'stock_item' || $type === 'packaging') {
                $pp = $this->purchasePrices->effectiveAt((int)$line->component_id, null, $asOf, null);
                $unitCost = $pp? (float)$pp->price : 0.0;
            } elseif ($type === 'semi_finished_product') {
                $prepared = $line->component; // morph to SemiFinishedProduct
                $recipe = $prepared?->recipe()->first();
                if ($recipe) {
                    $active = $recipe->activeVersion()->first() ?? $recipe->versions()->latest('created_at')->first();
                    if ($active) {
                        $child = $this->computeVersionCost($active, $asOf, $visited);
                        $unitCost = (float)$child['unit_cost'];
                    }
                }
            } elseif ($type === 'modifier_option') {
                // Modifiers usually affect selling price; exclude from production cost by default
                $unitCost = 0.0;
            }

            $subtotal += $qtyEff * $unitCost;
        }

        // Version-level cooking loss
        $vw = (int)($version->waste_bps ?? 0);
        $vp = max(0.0, min(0.9999, $vw / 10000.0));
        $inputsCost = $subtotal / (1.0 - $vp);

        $yield = max(0.000001, (float)$version->yield_quantity);
        $unitCost = $inputsCost / $yield;
        return ['total_cost' => $inputsCost, 'unit_cost' => $unitCost];
    }
}
