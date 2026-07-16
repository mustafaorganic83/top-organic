<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HR task assignable to an employee. Status lifecycle:
 * open -> in_progress -> done / cancelled.
 */
class HrTask extends BranchScopedModel
{
    use SoftDeletes;

    protected $table = 'hr_tasks';

    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }
}
