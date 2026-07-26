<?php

namespace App\Services;

use App\Contracts\Repositories\PreparedItemRepositoryInterface;
use App\Contracts\Repositories\RecipeVersionRepositoryInterface;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\SemiFinishedProduct;
use Illuminate\Support\Facades\DB;

class PreparedItemService
{
    public function __construct(
        private PreparedItemRepositoryInterface $preparedItems,
        private RecipeVersionRepositoryInterface $versions,
        private PreparedItemCostService $costs,
        private RecipeService $recipes,
    ) {}

    public function create(array $attributes): SemiFinishedProduct
    {
        /** @var SemiFinishedProduct $item */
        $item = DB::transaction(fn () => $this->preparedItems->create($attributes));
        // Ensure recipe header exists for this prepared item
        $this->preparedItems->getOrCreateRecipe($item);
        return $item;
    }

    public function getOrCreateRecipe(SemiFinishedProduct $item): Recipe
    {
        return $this->preparedItems->getOrCreateRecipe($item);
    }

    public function createVersion(SemiFinishedProduct $item, array $data): RecipeVersion
    {
        $recipe = $this->preparedItems->getOrCreateRecipe($item);
        return $this->recipes->createVersion($recipe, $data);
    }

    public function unitCost(SemiFinishedProduct $item, \DateTimeInterface|string $asOf): float
    {
        $asOf = $asOf instanceof \DateTimeInterface ? $asOf : new \DateTimeImmutable($asOf);
        $recipe = $item->recipe()->first();
        if (!$recipe) { return 0.0; }
        $active = $recipe->activeVersion()->first() ?? $recipe->versions()->latest('created_at')->first();
        if (!$active) { return 0.0; }
        $res = $this->costs->computeVersionCost($active, $asOf);
        return (float)$res['unit_cost'];
    }
}
