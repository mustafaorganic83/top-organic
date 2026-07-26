<?php

namespace App\Services\Costing\Strategies;

use App\Contracts\Repositories\CostHistoryRepositoryInterface;
use App\Services\Costing\Contracts\CostMethodStrategy;
use DateTimeInterface;

class StandardCostStrategy implements CostMethodStrategy
{
    public function __construct(private CostHistoryRepositoryInterface $history)
    {
    }

    public function unitCost(int $stockItemId, DateTimeInterface $asOf, array $options = []): float
    {
        $row = $this->history->effectiveAt('ITEM', $stockItemId, $asOf, 'STANDARD');
        return $row ? (float)$row->unit_cost : 0.0;
    }
}
