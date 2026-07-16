<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A received lot of a stockable in a warehouse. quantity_remaining is drawn
 * down as the lot is issued/consumed. FIFO issue orders by received_at; FEFO
 * orders by expiry_date. Each lot carries its own unit cost so FIFO/FEFO issue
 * values the deduction at the lot's actual acquisition cost.
 */
class StockBatch extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'expiry_date' => 'immutable_date',
            'received_date' => 'immutable_date',
            'quantity_received' => 'decimal:6',
            'quantity_remaining' => 'decimal:6',
            'unit_cost_amount' => 'integer',
            'received_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo(null, 'stockable_type', 'stockable_id');
    }
}
