<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchCatalogItem extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'source_revision' => 'integer', 'lock_version' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KdsStation::class, 'kds_station_id');
    }
}
