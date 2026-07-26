<?php

namespace App\Contracts\Repositories;

use App\Models\Recipe;
use App\Models\SemiFinishedProduct;

interface PreparedItemRepositoryInterface extends BaseRepositoryInterface
{
    public function findByName(string $name): ?SemiFinishedProduct;
    public function getOrCreateRecipe(SemiFinishedProduct $item): Recipe;
}
