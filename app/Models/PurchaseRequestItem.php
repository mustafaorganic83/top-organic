<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requested stock item line on a purchase request.
 */
class PurchaseRequestItem extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'estimated_unit_cost_amount' => 'integer',
            'line_number' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
