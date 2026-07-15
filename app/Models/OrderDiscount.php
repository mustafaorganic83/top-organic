<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDiscount extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['rate_bps' => 'integer', 'value_amount' => 'integer', 'applied_amount' => 'integer', 'occurred_at' => 'immutable_datetime'];
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
