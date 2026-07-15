<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'event_version' => 'integer', 'payload' => 'array', 'logical_clock' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
