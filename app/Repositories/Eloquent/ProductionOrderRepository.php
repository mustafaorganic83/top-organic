<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\ProductionOrderRepositoryInterface;
use App\Models\ProductionOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductionOrderRepository extends BaseEloquentRepository implements ProductionOrderRepositoryInterface
{
    public function __construct(ProductionOrder $model)
    {
        parent::__construct($model);
    }

    public function byStatus(string $status, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->where('status', $status)->paginate($perPage);
    }

    public function scheduledBetween($from, $to, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->whereBetween('scheduled_at', [$from, $to])->paginate($perPage);
    }
}
