<?php

namespace App\Contracts\Repositories;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Collection;

interface RecipeVersionRepositoryInterface extends BaseRepositoryInterface
{
    public function createFor(Recipe $recipe, array $attributes): RecipeVersion;
    public function duplicateWithComponents(RecipeVersion $source, Recipe $targetRecipe): RecipeVersion;
    public function publish(RecipeVersion $version): RecipeVersion; // set published_at
    public function activate(RecipeVersion $version): RecipeVersion; // set activated_at
    public function deactivate(RecipeVersion $version): RecipeVersion; // clear activated_at
    /** @return Collection<int, RecipeVersion> */
    public function listByRecipe(Recipe $recipe): Collection;
}
