<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCardTransaction extends TenantScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_transaction_id');
    }
}
