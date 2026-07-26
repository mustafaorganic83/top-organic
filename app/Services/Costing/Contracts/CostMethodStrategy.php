<?php

namespace App\Services\Costing\Contracts;

use DateTimeInterface;

interface CostMethodStrategy
{
    /** Return unit cost (major units) for given stock item at date. */
    public function unitCost(int $stockItemId, DateTimeInterface $asOf, array $options = []): float;
}
