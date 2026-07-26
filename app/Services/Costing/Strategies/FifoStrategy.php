<?php

namespace App\Services\Costing\Strategies;

use App\Models\InventoryMovement;
use App\Services\Costing\Contracts\CostMethodStrategy;
use DateTimeInterface;

class FifoStrategy implements CostMethodStrategy
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
        $receipts = [];
        $onhand = 0.0;
        foreach ($rows as $r) {
            $qty = (float)$r->quantity_delta;
            if ($qty > 0) { // receipt
                $receipts[] = ['qty' => $qty, 'cost' => ((int)$r->unit_cost_amount)/$this->moneyDivisor];
                $onhand += $qty;
            } else { // issue
                $toIssue = -$qty;
                while ($toIssue > 0 && !empty($receipts)) {
                    $layer =& $receipts[0];
                    $take = min($toIssue, $layer['qty']);
                    $layer['qty'] -= $take; $onhand -= $take; $toIssue -= $take;
                    if ($layer['qty'] <= 0.0000001) array_shift($receipts);
                }
            }
        }
        // Next issue would consume from the first available layer
        if (empty($receipts)) return 0.0;
        return (float)$receipts[0]['cost'];
    }
}
