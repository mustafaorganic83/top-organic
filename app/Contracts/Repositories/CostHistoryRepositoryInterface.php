<?php

namespace App\Contracts\Repositories;

use App\Models\CostHistory;

interface CostHistoryRepositoryInterface extends BaseRepositoryInterface
{
    public function effectiveAt(string $entityType, int|string $entityId, $at, string $method): ?CostHistory;
}
