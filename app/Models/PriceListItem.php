<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends TenantScopedModel
{
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }
}
