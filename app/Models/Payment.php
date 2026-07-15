<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['tender_amount' => 'integer', 'base_amount' => 'integer', 'fx_rate' => 'decimal:8', 'provider_snapshot' => 'array', 'captured_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('sequence');
    }
}
