<?php

namespace App\Services\Costing\Strategies;

use App\Contracts\Repositories\PurchasePriceRepositoryInterface;
use App\Services\Costing\Contracts\CostMethodStrategy;
use DateTimeInterface;

class LastPurchaseStrategy implements CostMethodStrategy
{
    public function __construct(private PurchasePriceRepositoryInterface $prices)
    {
    }

    public function unitCost(int $stockItemId, DateTimeInterface $asOf, array $options = []): float
    {
        $row = $this->prices->effectiveAt($stockItemId, $options['supplier_id'] ?? null, $asOf, $options['uom_id'] ?? null);
        return $row ? (float)$row->price : 0.0;
    }
}
