<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'current_balance' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class)->orderBy('transaction_date');
    }
}
