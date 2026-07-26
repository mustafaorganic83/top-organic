<?php

namespace App\Services;

use App\Contracts\Repositories\PurchasePriceRepositoryInterface;
use App\DTOs\PurchasePriceDTO;
use App\Models\PurchasePrice;
use App\Validation\PurchasePriceValidator;
use Illuminate\Support\Facades\DB;

class PurchasePriceService
{
    public function __construct(private PurchasePriceRepositoryInterface $repo)
    {
    }

    public function create(array $data): PurchasePrice
    {
        $valid = PurchasePriceValidator::validate($data);
        $dto = PurchasePriceDTO::fromArray($valid);
        return DB::transaction(function () use ($dto) {
            /** @var PurchasePrice $row */
            $row = $this->repo->create($dto->toArray());
            return $row;
        });
    }

    public function update(PurchasePrice $row, array $data): PurchasePrice
    {
        $valid = PurchasePriceValidator::validate($data);
        $dto = PurchasePriceDTO::fromArray($valid);
        return DB::transaction(fn () => $this->repo->update($row, $dto->toArray()));
    }

    public function delete(PurchasePrice $row): void
    {
        DB::transaction(fn () => $this->repo->delete($row));
    }
}
