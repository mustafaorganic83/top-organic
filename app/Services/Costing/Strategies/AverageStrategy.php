<?php

namespace App\Services\Costing\Strategies;

use App\Models\InventoryMovement;
use App\Services\Costing\Contracts\CostMethodStrategy;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

class AverageStrategy implements CostMethodStrategy
{
    public function __construct(private int $moneyDivisor = 100, private int $lookbackDays = 90)
    {}

    public function unitCost(int $stockItemId, DateTimeInterface $asOf, array $options = []): float
    {
        $from = (new DateTimeImmutable($asOf->format('Y-m-d H:i:s')))->sub(new DateInterval('P'.$this->lookbackDays.'D'));
        $rows = InventoryMovement::query()
            ->where('stockable_type', \App\Models\StockItem::class)
            ->where('stockable_id', $stockItemId)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $asOf)
            ->where('quantity_delta', '>', 0) // receipts only
            ->orderBy('occurred_at')
            ->get(['quantity_delta','unit_cost_amount']);
        if ($rows->isEmpty()) return 0.0;
        $sum = 0.0; $n = 0;
        foreach ($rows as $r) {
            $cost = ((int)$r->unit_cost_amount) / $this->moneyDivisor;
            $sum += $cost; $n++;
        }
        return $n ? $sum / $n : 0.0;
    }
}
