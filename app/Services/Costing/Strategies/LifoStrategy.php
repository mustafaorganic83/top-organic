<?php

namespace App\Services\Costing\Strategies;

use App\Models\InventoryMovement;
use App\Services\Costing\Contracts\CostMethodStrategy;
use DateTimeInterface;

class LifoStrategy implements CostMethodStrategy
{
    public function __construct(private int $moneyDivisor = 100)
    {}

    public function unitCost(int $stockItemId, DateTimeInterface $asOf, array $options = []): float
    {
        $rows = InventoryMovement::query()
            ->where('stockable_type', \App\Models\StockItem::class)
            ->where('stockable_id', $stockItemId)
            ->where('occurred_at', '<=', $asOf)
            ->orderBy('occurred_at')
            ->get(['quantity_delta','unit_cost_amount']);
        if ($rows->isEmpty()) return 0.0;
        $layers = [];
        foreach ($rows as $r) {
            $qty = (float)$r->quantity_delta;
            if ($qty > 0) {
                $layers[] = ['qty'=>$qty,'cost'=>((int)$r->unit_cost_amount)/$this->moneyDivisor];
            } else {
                $toIssue = -$qty;
                while ($toIssue > 0 && !empty($layers)) {
                    $idx = count($layers)-1; // last layer
                    $take = min($toIssue, $layers[$idx]['qty']);
                    $layers[$idx]['qty'] -= $take; $toIssue -= $take;
                    if ($layers[$idx]['qty'] <= 0.0000001) array_pop($layers);
                }
            }
        }
        if (empty($layers)) return 0.0;
        $idx = count($layers)-1;
        return (float)$layers[$idx]['cost'];
    }
}
