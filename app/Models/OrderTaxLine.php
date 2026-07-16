<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTaxLine extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['calculation_revision' => 'integer', 'policy_revision' => 'integer', 'taxable_amount' => 'integer', 'rate_bps' => 'integer', 'tax_amount' => 'integer', 'is_inclusive' => 'boolean', 'calculation_order' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
