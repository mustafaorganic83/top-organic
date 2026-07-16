<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scored evaluation of a supplier (quality, delivery, price, compliance).
 * Multiple evaluations can exist per supplier over time.
 */
class SupplierEvaluation extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'score' => 'decimal:2',
            'evaluated_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
