<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintJob extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'priority' => 'integer', 'attempt_count' => 'integer', 'available_at' => 'immutable_datetime', 'printed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(PrintRoute::class, 'print_route_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PrintAttempt::class);
    }
}
