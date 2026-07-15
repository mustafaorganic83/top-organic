<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['last_order_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class);
    }
}
