<?php

namespace App\Contracts\Repositories;

use App\Models\PurchasePrice;

interface PurchasePriceRepositoryInterface extends BaseRepositoryInterface
{
    public function latestForItem(int|string $itemId): ?PurchasePrice;
    public function effectiveAt(int|string $itemId, ?int $supplierId, $at, ?int $uomId = null): ?PurchasePrice;
}
