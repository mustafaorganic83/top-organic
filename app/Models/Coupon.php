<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends TenantScopedModel
{
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['maximum_redemptions' => 'integer', 'maximum_per_customer' => 'integer', 'redemption_count' => 'integer', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
