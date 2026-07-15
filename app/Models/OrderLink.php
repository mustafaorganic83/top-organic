<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLink extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function targetOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'target_order_id');
    }
}
