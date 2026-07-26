<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\RecipeComponentRepositoryInterface;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use Illuminate\Support\Collection;

class RecipeComponentRepository extends BaseEloquentRepository implements RecipeComponentRepositoryInterface
{
    public function __construct(RecipeComponent $model)
    {
        parent::__construct($model);
    }

    public function addItem(RecipeVersion $version, int $itemId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent
    {
        /** @var RecipeComponent $line */
        $line = $this->create([
            'recipe_version_id' => $version->getKey(),
            'component_type' => 'stock_item',
            'component_id' => $itemId,
            'quantity' => $quantity,
            'waste_bps' => $wasteBps,
            'sort_order' => $sortOrder,
        ]);
        return $line;
    }

    public function addPrepared(RecipeVersion $version, int $semiFinishedId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent
    {
        /** @var RecipeComponent $line */
        $line = $this->create([
            'recipe_version_id' => $version->getKey(),
            'component_type' => 'semi_finished_product',
            'component_id' => $semiFinishedId,
            'quantity' => $quantity,
            'waste_bps' => $wasteBps,
            'sort_order' => $sortOrder,
        ]);
        return $line;
    }

    public function addPackaging(RecipeVersion $version, int $itemId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent
    {
        // Packaging is modeled as a stock item
        return $this->addItem($version, $itemId, $quantity, $wasteBps, $sortOrder);
    }

    public function addModifier(RecipeVersion $version, int $modifierOptionId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent
    {
        /** @var RecipeComponent $line */
        $line = $this->create([
            'recipe_version_id' => $version->getKey(),
            'component_type' => 'modifier_option',
            'component_id' => $modifierOptionId,
            'quantity' => $quantity,
            'waste_bps' => $wasteBps,
            'sort_order' => $sortOrder,
        ]);
        return $line;
    }

    public function updateLine(RecipeComponent $line, array $attributes): RecipeComponent
    {
        return parent::update($line, $attributes);
    }

    public function remove(RecipeComponent $line): void
    {
        parent::delete($line);
    }

    public function list(RecipeVersion $version): Collection
    {
        return $version->components()->orderBy('sort_order')->get();
    }
}
