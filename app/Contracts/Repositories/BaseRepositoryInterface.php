<?php

namespace App\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

interface BaseRepositoryInterface
{
    public function query(): Builder;

    /** @return Model|null */
    public function find(string $id);

    /** @return Model */
    public function findOrFail(string $id): Model;

    /** @return Model */
    public function create(array $attributes): Model;

    /** @return Model */
    public function update(Model $model, array $attributes): Model;

    public function delete(Model $model): void;

    /** @return LengthAwarePaginator */
    public function paginate(int $perPage = 15, array $criteria = []): LengthAwarePaginator;
}
