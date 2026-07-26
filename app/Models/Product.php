<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'is_meal' => 'boolean',
            'calories' => 'integer',
            'nutrition_summary' => 'array',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ProductModifierGroup::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'entity', 'entity_type', 'entity_id')
            ->orderBy('sort_order');
    }

    public function allergenTags(): HasMany
    {
        return $this->hasMany(EntityAllergen::class, 'entity_id')
            ->where('entity_type', 'product');
    }

    /**
     * The recipes of this dish, one per meal-size variant. Recipes hang off
     * product_variants, so they are reached through the variant.
     */
    public function recipes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Recipe::class,
            ProductVariant::class,
            'product_id',
            'owner_id',
            'id',
            'id',
        )->where('recipes.owner_type', 'product_variant');
    }
}
