<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An append-only inventory ledger row. Negative quantity_delta is a deduction
 * (consumption/waste), positive is an addition (production/adjustment). The
 * client_operation_id makes automatic consumption offline-safe and idempotent.
 */
class InventoryMovement extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:6',
            'unit_cost_amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo(null, 'stockable_type', 'stockable_id');
    }
}
