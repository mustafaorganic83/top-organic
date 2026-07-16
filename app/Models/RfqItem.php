<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line item on a Request for Quotation.
 */
class RfqItem extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'required_date' => 'date',
            'line_number' => 'integer',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
