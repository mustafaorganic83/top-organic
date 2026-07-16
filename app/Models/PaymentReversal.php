<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReversal extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'original_payment_id');
    }

    public function reversalPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'reversal_payment_id');
    }
}
