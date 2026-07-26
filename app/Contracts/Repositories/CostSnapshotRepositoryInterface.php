<?php

namespace App\Contracts\Repositories;

use App\Models\CostSnapshot;

interface CostSnapshotRepositoryInterface extends BaseRepositoryInterface
{
    public function latestFor(string $entityType, int|string $entityId, string $method): ?CostSnapshot;
}
