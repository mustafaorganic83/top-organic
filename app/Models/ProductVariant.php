<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'lock_version' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function branchCatalogItems(): HasMany
    {
        return $this->hasMany(BranchCatalogItem::class);
    }
}
