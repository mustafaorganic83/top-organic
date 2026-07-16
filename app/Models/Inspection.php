<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quality inspection of a goods receipt.
 * Status lifecycle: pending → passed / failed.
 */
class Inspection extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'inspected_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
