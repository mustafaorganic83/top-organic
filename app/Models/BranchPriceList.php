<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPriceList extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['priority' => 'integer', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime'];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
