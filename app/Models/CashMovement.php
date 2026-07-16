<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function drawerSession(): BelongsTo
    {
        return $this->belongsTo(CashDrawerSession::class, 'cash_drawer_session_id');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_movement_id');
    }
}
