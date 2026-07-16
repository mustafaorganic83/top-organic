<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscountRule extends TenantScopedModel
{
    protected function casts(): array
    {
        return ['rate_bps' => 'integer', 'fixed_amount' => 'integer', 'minimum_order_amount' => 'integer', 'maximum_discount_amount' => 'integer', 'conditions' => 'array', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime', 'revision' => 'integer', 'lock_version' => 'integer'];
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
