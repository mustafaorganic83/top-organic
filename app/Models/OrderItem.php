<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['line_number' => 'integer', 'quantity' => 'decimal:6', 'catalog_snapshot' => 'array', 'unit_price_amount' => 'integer', 'gross_amount' => 'integer', 'discount_amount' => 'integer', 'tax_amount' => 'integer', 'net_amount' => 'integer', 'course_number' => 'integer', 'seat_number' => 'integer', 'lock_version' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}
