<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashDrawerSession extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['opening_amount' => 'integer', 'expected_amount' => 'integer', 'counted_amount' => 'integer', 'variance_amount' => 'integer', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function drawer(): BelongsTo
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
