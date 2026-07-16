<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to replenish stock (draft -> submitted -> approved / rejected).
 * Raised manually or generated from reorder points; feeds procurement.
 * Receiving lands stock via the batch-receipt endpoint.
 */
class PurchaseRequest extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }
}
