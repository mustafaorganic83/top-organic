<?php

namespace App\Services\Snapshot;

use App\Contracts\Repositories\CostSnapshotRepositoryInterface;
use App\Models\Currency;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\Costing\CostMethod;
use App\Services\Costing\CostingEngine;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

class SnapshotEngine
{
    public function __construct(
        private CostingEngine $costing,
        private CostSnapshotRepositoryInterface $snapshots,
    ) {}

    public function snapshotItem(string $itemId, DateTimeInterface $asOf, string $method = CostMethod::LAST_PURCHASE, array $options = []): void
    {
        $currencyId = $options['currency_id'] ?? $this->resolveCurrencyId();
        if (!$currencyId) { Log::warning('Snapshot skipped: no currency'); return; }
        $unit = $this->costing->ingredientUnitCost($itemId, $asOf, $method, $options);
        $this->snapshots->create([
            'entity_type' => 'ITEM',
            'entity_id' => $itemId,
            'as_of_date' => $asOf,
            'method' => $method,
            'unit_cost' => $unit,
            'currency_id' => $currencyId,
            'details' => ['source' => 'auto', 'options' => $options],
        ]);
    }

    public function snapshotRecipe(RecipeVersion $version, DateTimeInterface $asOf, string $method = CostMethod::WEIGHTED_AVG, array $options = []): void
    {
        $currencyId = $options['currency_id'] ?? $this->resolveCurrencyId();
        if (!$currencyId) { Log::warning('Snapshot skipped: no currency'); return; }
        $res = $this->costing->recipeCost($version, $asOf, $method, $options);
        // Store snapshot for the Recipe header
        $recipeId = $version->recipe_id;
        $this->snapshots->create([
            'entity_type' => 'RECIPE',
            'entity_id' => $recipeId,
            'as_of_date' => $asOf,
            'method' => $method,
            'unit_cost' => $res['unit_cost'],
            'currency_id' => $currencyId,
            'details' => ['version_id' => $version->getKey(), 'lines' => $res['lines'] ?? null],
        ]);
        // If the recipe belongs to a prepared item, also snapshot with entity_type PREPARED
        /** @var Recipe $recipe */
        $recipe = $version->recipe()->first();
        $owner = $recipe?->owner;
        if ($owner && $owner instanceof \App\Models\SemiFinishedProduct) {
            $this->snapshots->create([
                'entity_type' => 'PREPARED',
                'entity_id' => $owner->getKey(),
                'as_of_date' => $asOf,
                'method' => $method,
                'unit_cost' => $res['unit_cost'],
                'currency_id' => $currencyId,
                'details' => ['recipe_id' => $recipeId, 'version_id' => $version->getKey()],
            ]);
        }
    }

    private function resolveCurrencyId(): ?string
    {
        /** @var Currency|null $c */
        $c = Currency::query()->first();
        return (string)$c?->id;
    }
}
