<?php

namespace App\Contracts\Repositories;

use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use Illuminate\Support\Collection;

interface RecipeComponentRepositoryInterface extends BaseRepositoryInterface
{
    public function addItem(RecipeVersion $version, int $itemId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent;
    public function addPrepared(RecipeVersion $version, int $semiFinishedId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent;
    public function addPackaging(RecipeVersion $version, int $itemId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent;
    public function addModifier(RecipeVersion $version, int $modifierOptionId, string $quantity, ?int $wasteBps = null, ?int $sortOrder = null): RecipeComponent;
    public function updateLine(RecipeComponent $line, array $attributes): RecipeComponent;
    public function remove(RecipeComponent $line): void;
    /** @return Collection<int, RecipeComponent> */
    public function list(RecipeVersion $version): Collection;
}
