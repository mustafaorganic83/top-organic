<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryEvent extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'location' => 'array', 'payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(DeliveryFulfillment::class, 'delivery_fulfillment_id');
    }
}
