<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends TenantScopedModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'debit_amount' => 'integer',
            'credit_amount' => 'integer',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AccountingProject::class, 'project_id');
    }
}
