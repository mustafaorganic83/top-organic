<?php

namespace App\Services;

use App\Contracts\Repositories\ProductionOrderRepositoryInterface;
use App\DTOs\ProductionOrderDTO;
use App\Models\ProductionOrder;
use App\Validation\ProductionOrderValidator;
use Illuminate\Support\Facades\DB;

class ProductionOrderService
{
    public function __construct(private ProductionOrderRepositoryInterface $repo)
    {
    }

    public function create(array $data): ProductionOrder
    {
        $valid = ProductionOrderValidator::validate($data);
        $dto = ProductionOrderDTO::fromArray($valid);
        return DB::transaction(fn () => $this->repo->create($dto->toArray()));
    }

    public function update(ProductionOrder $order, array $data): ProductionOrder
    {
        $valid = ProductionOrderValidator::validate($data);
        $dto = ProductionOrderDTO::fromArray($valid);
        return DB::transaction(fn () => $this->repo->update($order, $dto->toArray()));
    }

    public function progress(ProductionOrder $order, string $newStatus): ProductionOrder
    {
        return DB::transaction(function () use ($order, $newStatus) {
            return $this->repo->update($order, ['status' => $newStatus]);
        });
    }

    public function delete(ProductionOrder $order): void
    {
        DB::transaction(fn () => $this->repo->delete($order));
    }
}
