<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Employee leave request. Status lifecycle:
 * draft -> submitted -> approved / rejected / cancelled.
 */
class LeaveRequest extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'days' => 'decimal:2',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
