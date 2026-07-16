<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['calories' => 'integer', 'sort_order' => 'integer', 'lock_version' => 'integer'];
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

    public function media(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'entity', 'entity_type', 'entity_id')
            ->orderBy('sort_order');
    }

    public function recipe(): MorphOne
    {
        return $this->morphOne(Recipe::class, 'owner', 'owner_type', 'owner_id');
    }
}
