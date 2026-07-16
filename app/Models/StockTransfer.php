<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A movement of stock between two warehouses in the same branch. Dispatch
 * deducts the source (in_transit); receipt adds the destination (received),
 * so in-transit stock belongs to neither warehouse's on-hand.
 */
class StockTransfer extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
