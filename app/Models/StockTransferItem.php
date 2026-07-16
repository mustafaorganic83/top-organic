<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single stockable line on a stock transfer.
 */
class StockTransferItem extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'quantity_received' => 'decimal:6',
            'unit_cost_amount' => 'integer',
            'line_number' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo(null, 'stockable_type', 'stockable_id');
    }
}
