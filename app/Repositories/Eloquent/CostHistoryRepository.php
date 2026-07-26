<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CostHistoryRepositoryInterface;
use App\Models\CostHistory;
use Illuminate\Support\Carbon;

class CostHistoryRepository extends BaseEloquentRepository implements CostHistoryRepositoryInterface
{
    public function __construct(CostHistory $model)
    {
        parent::__construct($model);
    }

    public function effectiveAt(string $entityType, int|string $entityId, $at, string $method): ?CostHistory
    {
        $at = $at instanceof \DateTimeInterface ? $at : Carbon::parse($at);
        /** @var CostHistory|null $row */
        $row = $this->query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('method', $method)
            ->where('effective_from', '<=', $at)
            ->where(function ($w) use ($at) {
                $w->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            })
            ->orderByDesc('effective_from')
            ->first();
        return $row;
    }
}
