<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line on a goods receipt recording what was actually received.
 */
class GoodsReceiptItem extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:6',
            'quantity_received' => 'decimal:6',
            'unit_price_amount' => 'integer',
            'line_number' => 'integer',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
