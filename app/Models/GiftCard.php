<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftCard extends TenantScopedModel
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['balance_amount' => 'integer', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class);
    }
}
