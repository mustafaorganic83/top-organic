<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipTier extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['minimum_spend' => 'integer', 'discount_rate_bps' => 'integer', 'lock_version' => 'integer'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class);
    }
}
