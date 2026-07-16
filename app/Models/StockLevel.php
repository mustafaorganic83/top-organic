<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-branch, per-warehouse on-hand quantity for a stockable (ingredient or
 * semi-finished product). Carries a moving-average cost and a reserved
 * quantity; maintained by the inventory ledger and guarded by optimistic
 * locking.
 */
class StockLevel extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:6',
            'reserved_quantity' => 'decimal:6',
            'reorder_level' => 'decimal:6',
            'average_cost_amount' => 'integer',
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
