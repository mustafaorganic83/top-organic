<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\RecipeRepositoryInterface;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Collection;

class RecipeRepository extends BaseEloquentRepository implements RecipeRepositoryInterface
{
    public function __construct(Recipe $model)
    {
        parent::__construct($model);
    }

    public function findByCode(string $code): ?Recipe
    {
        /** @var Recipe|null $r */
        $r = $this->query()->where('code', $code)->first();
        return $r;
    }

    public function activeVersion(Recipe $recipe): ?RecipeVersion
    {
        /** @var RecipeVersion|null $v */
        $v = $recipe->activeVersion()->first();
        return $v;
    }

    public function setActiveVersion(Recipe $recipe, RecipeVersion $version): Recipe
    {
        $recipe->active_version_id = $version->getKey();
        $recipe->save();
        return $recipe;
    }

    public function versions(Recipe $recipe): Collection
    {
        return $recipe->versions()->orderByDesc('created_at')->get();
    }
}
