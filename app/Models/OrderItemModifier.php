<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['line_number' => 'integer', 'quantity' => 'decimal:6', 'unit_surcharge_amount' => 'integer', 'total_surcharge_amount' => 'integer'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class, 'modifier_option_id');
    }
}
