<?php

namespace App\Services;

use App\Contracts\Repositories\CurrencyRepositoryInterface;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;

class CurrencyService
{
    public function __construct(private CurrencyRepositoryInterface $repo)
    {
    }

    public function create(array $data): Currency
    {
        return DB::transaction(fn () => $this->repo->create($data));
    }

    public function update(Currency $currency, array $data): Currency
    {
        return DB::transaction(fn () => $this->repo->update($currency, $data));
    }

    public function delete(Currency $currency): void
    {
        DB::transaction(fn () => $this->repo->delete($currency));
    }
}
