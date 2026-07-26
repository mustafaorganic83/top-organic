<?php

namespace App\Contracts\Repositories;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Collection;

interface RecipeRepositoryInterface extends BaseRepositoryInterface
{
    public function findByCode(string $code): ?Recipe;
    public function activeVersion(Recipe $recipe): ?RecipeVersion;
    public function setActiveVersion(Recipe $recipe, RecipeVersion $version): Recipe;
    /** @return Collection<int, RecipeVersion> */
    public function versions(Recipe $recipe): Collection;
}
