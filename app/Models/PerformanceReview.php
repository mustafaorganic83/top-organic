<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Employee performance evaluation for a period. Status lifecycle:
 * draft -> submitted -> acknowledged. Score is 0-100.
 */
class PerformanceReview extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'submitted_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
