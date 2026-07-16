<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPaymentMethod extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'supports_offline' => 'boolean', 'minimum_amount' => 'integer',
            'maximum_amount' => 'integer', 'lock_version' => 'integer'];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
