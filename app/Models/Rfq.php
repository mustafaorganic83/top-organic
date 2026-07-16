<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Request for Quotation sent to one or more suppliers.
 * Status lifecycle: draft → sent → closed.
 */
class Rfq extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }
}
