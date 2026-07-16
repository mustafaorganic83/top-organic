<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock-count session over one warehouse. A physical count covers the whole
 * warehouse; a cycle count covers a rolling subset. Opening freezes the
 * expected snapshot per line; posting writes adjustment movements for each
 * variance and reconciles the on-hand level.
 */
class StockCount extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'posted_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }
}
