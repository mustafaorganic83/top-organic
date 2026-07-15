<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class PosShift extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'sequence' => 'integer', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function drawerSessions(): HasMany
    {
        return $this->hasMany(CashDrawerSession::class);
    }
}
