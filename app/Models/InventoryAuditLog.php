<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An append-only inventory audit trail row. Records every warehouse / batch /
 * transfer / count / purchase-request / adjustment action with the actor and a
 * JSON before/after snapshot for tamper-evident review.
 */
class InventoryAuditLog extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(null, 'entity_type', 'entity_id');
    }
}
