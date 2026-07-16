<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A price quotation received from a supplier in response to an RFQ.
 * Status lifecycle: received → shortlisted → awarded / rejected.
 */
class Quotation extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'total_amount' => 'integer',
            'valid_until' => 'date',
            'received_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }
}
