<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\WasteRepositoryInterface;
use App\Models\Waste;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WasteRepository extends BaseEloquentRepository implements WasteRepositoryInterface
{
    public function __construct(Waste $model)
    {
        parent::__construct($model);
    }

    public function between($from, $to, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->whereBetween('occurred_at', [$from, $to])->paginate($perPage);
    }

    public function ofType(string $type, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->where('waste_type', $type)->paginate($perPage);
    }
}
