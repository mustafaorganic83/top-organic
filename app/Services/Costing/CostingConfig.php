<?php

namespace App\Services\Costing;

class CostingConfig
{
    public function __construct(
        public string $wasteMode = 'multiplicative', // 'additive'|'multiplicative'
        public int $defaultDeptWasteBps = 0,
        public string $lineWasteField = 'waste_bps',
        public string $versionWasteField = 'waste_bps',
        public bool $includePackaging = true,
        public bool $includeModifiers = false,
        public int $moneyDivisor = 100, // for InventoryMovement.unit_cost_amount
        public int $avgLookbackDays = 90,
    ) {}
}
