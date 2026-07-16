<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Append-only procurement audit trail. Records every state transition and
 * write operation with the actor, device, and a JSON before/after snapshot.
 */
class ProcurementAuditLog extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
