<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'catalog_snapshot' => 'array', 'quantity' => 'decimal:6', 'unit_price_amount' => 'integer', 'gross_amount' => 'integer', 'discount_amount' => 'integer', 'net_amount' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
