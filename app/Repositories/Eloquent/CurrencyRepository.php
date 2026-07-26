<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Models\Currency;

class CurrencyRepository extends BaseEloquentRepository implements CurrencyRepositoryInterface
{
    public function __construct(Currency $model)
    {
        parent::__construct($model);
    }

    public function findByCode(string $code): ?Currency
    {
        /** @var Currency|null $c */
        $c = $this->query()->where('code', strtoupper($code))->first();
        return $c;
    }
}
