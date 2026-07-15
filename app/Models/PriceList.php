<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends TenantScopedModel
{
    protected function casts(): array
    {
        return ['revision' => 'integer', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(BranchPriceList::class);
    }
}
