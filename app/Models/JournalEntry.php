<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'period_month' => 'integer',
            'posted_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_number');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by');
    }

    /** Verify the entry balances (debits = credits). */
    public function isBalanced(): bool
    {
        $totals = $this->lines->reduce(fn ($carry, $line) => [
            $carry[0] + $line->debit_amount,
            $carry[1] + $line->credit_amount,
        ], [0, 0]);

        return $totals[0] === $totals[1];
    }
}
