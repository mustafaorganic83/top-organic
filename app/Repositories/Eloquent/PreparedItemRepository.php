<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PreparedItemRepositoryInterface;
use App\Models\Recipe;
use App\Models\SemiFinishedProduct;

class PreparedItemRepository extends BaseEloquentRepository implements PreparedItemRepositoryInterface
{
    public function __construct(SemiFinishedProduct $model)
    {
        parent::__construct($model);
    }

    public function findByName(string $name): ?SemiFinishedProduct
    {
        /** @var SemiFinishedProduct|null $m */
        $m = $this->query()->where('name', $name)->first();
        return $m;
    }

    public function getOrCreateRecipe(SemiFinishedProduct $item): Recipe
    {
        $recipe = $item->recipe()->first();
        if ($recipe) { return $recipe; }
        /** @var Recipe $created */
        $created = Recipe::query()->create([
            'owner_type' => get_class($item),
            'owner_id' => $item->getKey(),
            'code' => 'PREP-'.$item->getKey(),
            'name_ar' => $item->name ?? ('Prepared '.$item->getKey()),
            'name_en' => $item->name ?? ('Prepared '.$item->getKey()),
            'active' => false,
        ]);
        return $created;
    }
}
