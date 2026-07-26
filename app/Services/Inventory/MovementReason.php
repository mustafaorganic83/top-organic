<?php

namespace App\Services\Inventory;

final class MovementReason
{
    public const CONSUMPTION = 'consumption';
    public const PRODUCTION  = 'production';
    public const TRANSFER_OUT = 'transfer_out';
    public const TRANSFER_IN  = 'transfer_in';
    public const ADJUSTMENT  = 'adjustment';
    public const PURCHASE    = 'purchase';
    public const RETURN_SUPPLIER = 'return_supplier';
    public const RETURN_CUSTOMER = 'return_customer';
    public const WASTE       = 'waste';
}
