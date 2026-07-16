<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Append-only HR audit trail. Records every state transition and write
 * operation with the actor, device, and a JSON before/after snapshot.
 */
class EmployeeHistory extends BranchScopedModel
{
    protected $table = 'employee_history';

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
