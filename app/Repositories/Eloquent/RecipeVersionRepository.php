<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\RecipeVersionRepositoryInterface;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RecipeVersionRepository extends BaseEloquentRepository implements RecipeVersionRepositoryInterface
{
    public function __construct(RecipeVersion $model)
    {
        parent::__construct($model);
    }

    public function createFor(Recipe $recipe, array $attributes): RecipeVersion
    {
        $attributes['recipe_id'] = $recipe->getKey();
        /** @var RecipeVersion $v */
        $v = $this->create($attributes);
        return $v;
    }

    public function duplicateWithComponents(RecipeVersion $source, Recipe $targetRecipe): RecipeVersion
    {
        /** @var RecipeVersion $clone */
        $clone = $this->createFor($targetRecipe, [
            'revision' => (int)($source->revision + 1),
            'yield_quantity' => $source->yield_quantity,
            'waste_bps' => $source->waste_bps,
            'nutrition' => $source->nutrition,
        ]);
        $lines = RecipeComponent::query()->where('recipe_version_id', $source->getKey())->get();
        foreach ($lines as $line) {
            RecipeComponent::query()->create([
                'recipe_version_id' => $clone->getKey(),
                'component_type' => $line->component_type,
                'component_id' => $line->component_id,
                'quantity' => $line->quantity,
                'waste_bps' => $line->waste_bps,
                'sort_order' => $line->sort_order,
                'unit_cost_amount' => $line->unit_cost_amount,
                'line_cost_amount' => $line->line_cost_amount,
            ]);
        }
        return $clone;
    }

    public function publish(RecipeVersion $version): RecipeVersion
    {
        $version->published_at = Carbon::now();
        $version->save();
        return $version;
    }

    public function activate(RecipeVersion $version): RecipeVersion
    {
        $version->activated_at = Carbon::now();
        $version->save();
        return $version;
    }

    public function deactivate(RecipeVersion $version): RecipeVersion
    {
        $version->activated_at = null;
        $version->save();
        return $version;
    }

    public function listByRecipe(Recipe $recipe): Collection
    {
        return $recipe->versions()->orderByDesc('created_at')->get();
    }
}
