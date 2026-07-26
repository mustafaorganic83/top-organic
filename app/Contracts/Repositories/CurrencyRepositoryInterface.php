<?php

namespace App\Contracts\Repositories;

use App\Models\Currency;

interface CurrencyRepositoryInterface extends BaseRepositoryInterface
{
    public function findByCode(string $code): ?Currency;
}
