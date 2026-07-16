<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'transaction_date' => 'immutable_date',
            'debit_amount' => 'integer',
            'credit_amount' => 'integer',
            'running_balance' => 'integer',
            'reconciled_at' => 'immutable_datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
