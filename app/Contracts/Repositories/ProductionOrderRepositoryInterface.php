<?php

namespace App\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductionOrderRepositoryInterface extends BaseRepositoryInterface
{
    public function byStatus(string $status, int $perPage = 15): LengthAwarePaginator;
    public function scheduledBetween($from, $to, int $perPage = 15): LengthAwarePaginator;
}
