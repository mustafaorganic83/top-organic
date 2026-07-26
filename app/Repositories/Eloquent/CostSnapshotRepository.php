<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CostSnapshotRepositoryInterface;
use App\Models\CostSnapshot;

class CostSnapshotRepository extends BaseEloquentRepository implements CostSnapshotRepositoryInterface
{
    public function __construct(CostSnapshot $model)
    {
        parent::__construct($model);
    }

    public function latestFor(string $entityType, int|string $entityId, string $method): ?CostSnapshot
    {
        /** @var CostSnapshot|null $row */
        $row = $this->query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('method', $method)
            ->orderByDesc('as_of_date')
            ->first();
        return $row;
    }
}
