<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A priced line item on a supplier quotation.
 */
class QuotationItem extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price_amount' => 'integer',
            'total_amount' => 'integer',
            'line_number' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
