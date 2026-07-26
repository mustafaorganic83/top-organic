<?php

namespace App\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WasteRepositoryInterface extends BaseRepositoryInterface
{
    public function between($from, $to, int $perPage = 15): LengthAwarePaginator;
    public function ofType(string $type, int $perPage = 15): LengthAwarePaginator;
}
