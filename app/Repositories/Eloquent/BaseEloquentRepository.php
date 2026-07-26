<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\BaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class BaseEloquentRepository implements BaseRepositoryInterface
{
    public function __construct(protected Model $model)
    {
    }

    public function query(): Builder
    {
        return $this->model->newQuery();
    }

    public function find(string $id)
    {
        return $this->query()->find($id);
    }

    public function findOrFail(string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    public function create(array $attributes): Model
    {
        /** @var Model $created */
        $created = $this->query()->create($attributes);
        return $created;
    }

    public function update(Model $model, array $attributes): Model
    {
        $model->fill($attributes);
        $model->save();
        return $model;
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    public function paginate(int $perPage = 15, array $criteria = []): LengthAwarePaginator
    {
        $q = $this->query();
        foreach ($criteria as $col => $val) {
            if ($val === null) continue;
            $q->where($col, $val);
        }
        return $q->paginate($perPage);
    }
}
