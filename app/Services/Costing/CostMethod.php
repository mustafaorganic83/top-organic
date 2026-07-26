<?php

namespace App\Services\Costing;

final class CostMethod
{
    public const LAST_PURCHASE = 'LAST_PURCHASE';
    public const AVERAGE = 'AVERAGE';
    public const FIFO = 'FIFO';
    public const LIFO = 'LIFO';
    public const WEIGHTED_AVG = 'WEIGHTED_AVG';
    public const STANDARD = 'STANDARD';
}
