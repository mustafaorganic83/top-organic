<?php

namespace App\Services\Costing;

use App\Contracts\Repositories\PurchasePriceRepositoryInterface;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Services\Costing\Contracts\CostMethodStrategy;
use DateTimeInterface;

class CostingEngine
{
    /** @param array<string,CostMethodStrategy> $strategies */
    public function __construct(
        private array $strategies,
        private CostingConfig $config,
        private PurchasePriceRepositoryInterface $purchasePrices,
    ) {}

    public function actualConsumption(float $qty, int $prepWasteBps = 0, int $deptWasteBps = 0, int $cookingLossBps = 0): float
    {
        $p = max(0.0, min(0.9999, $prepWasteBps/10000.0));
        $d = max(0.0, min(0.9999, $deptWasteBps/10000.0));
        $c = max(0.0, min(0.9999, $cookingLossBps/10000.0));
        if ($this->config->wasteMode === 'additive') {
            return $qty + ($qty*$p) + ($qty*$d) + ($qty*$c);
        }
        // multiplicative default
        return $qty * (1.0+$p) * (1.0+$d) * (1.0+$c);
    }

    public function ingredientUnitCost(int $stockItemId, DateTimeInterface $asOf, string $method, array $options = []): float
    {
        $m = strtoupper($method);
        $strategy = $this->strategies[$m] ?? null;
        if ($strategy instanceof CostMethodStrategy) {
            return $strategy->unitCost($stockItemId, $asOf, $options);
        }
        // fallback to last purchase
        $row = $this->purchasePrices->effectiveAt($stockItemId, $options['supplier_id'] ?? null, $asOf, $options['uom_id'] ?? null);
        return $row ? (float)$row->price : 0.0;
    }

    /** Compute per-line cost using chosen cost method; returns ['qty'=>float,'unit_cost'=>float,'line_cost'=>float] */
    public function componentCost(RecipeComponent $line, RecipeVersion $version, DateTimeInterface $asOf, string $method, array $options = []): array
    {
        $type = (string)$line->component_type;
        if ($type === 'modifier_option' && !$this->config->includeModifiers) {
            return ['qty'=>0.0,'unit_cost'=>0.0,'line_cost'=>0.0];
        }
        if ($type === 'packaging' && !$this->config->includePackaging) {
            return ['qty'=>0.0,'unit_cost'=>0.0,'line_cost'=>0.0];
        }
        $baseQty = (float)$line->quantity;
        $prepWaste = (int)($line->{$this->config->lineWasteField} ?? 0);
        $deptWaste = (int)($options['dept_waste_bps'] ?? $this->config->defaultDeptWasteBps);
        $cookLoss = (int)($version->{$this->config->versionWasteField} ?? 0);
        $qtyEff = $this->actualConsumption($baseQty, $prepWaste, $deptWaste, $cookLoss);

        $unit = 0.0;
        if ($type === 'stock_item' || $type === 'packaging') {
            $unit = $this->ingredientUnitCost((int)$line->component_id, $asOf, $method, $options);
        } elseif ($type === 'semi_finished_product') {
            $prepared = $line->component; $recipe = $prepared?->recipe()->first();
            if ($recipe) {
                $active = $recipe->activeVersion()->first() ?? $recipe->versions()->latest('created_at')->first();
                if ($active) {
                    $child = $this->recipeCost($active, $asOf, $method, $options);
                    $unit = (float)$child['unit_cost'];
                }
            }
        } elseif ($type === 'modifier_option') {
            $unit = 0.0; // configurable later
        }
        $lineCost = $qtyEff * $unit;
        return ['qty'=>$qtyEff,'unit_cost'=>$unit,'line_cost'=>$lineCost];
    }

    /** Returns ['total_cost'=>float,'unit_cost'=>float,'lines'=>[...]]; pass sell_price to get margins. */
    public function recipeCost(RecipeVersion $version, DateTimeInterface $asOf, string $method, array $options = []): array
    {
        $total = 0.0; $lines = [];
        foreach ($version->components()->orderBy('sort_order')->get() as $line) {
            $c = $this->componentCost($line, $version, $asOf, $method, $options);
            $lines[] = $c; $total += $c['line_cost'];
        }
        $yield = max(0.000001, (float)$version->yield_quantity);
        $unit = $total / $yield;
        $out = ['total_cost'=>$total,'unit_cost'=>$unit,'lines'=>$lines];
        if (isset($options['sell_price'])) {
            $price = (float)$options['sell_price'];
            $foodCostPct = $price>0 ? $unit/$price : 0.0;
            $grossProfit = $price - $unit;
            $contribution = $grossProfit; // لاحقًا يمكن خصم ثابت/متغير
            $markup = $unit>0 ? ($price - $unit)/$unit : 0.0;
            $netCost = $unit; // قابل للتخصيص لاحقًا
            $out += compact('foodCostPct','grossProfit','contribution','markup','netCost');
        }
        return $out;
    }
}
