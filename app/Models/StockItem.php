<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A raw ingredient or packaging item, valued per stock unit (minor units).
 * Consumed by recipe components and drawn down by the inventory ledger.
 */
class StockItem extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_perishable' => 'boolean',
            'is_batch_tracked' => 'boolean',
            'unit_cost_amount' => 'integer',
            'default_waste_bps' => 'integer',
            'calories_per_unit' => 'integer',
            'min_stock' => 'decimal:6',
            'max_stock' => 'decimal:6',
            'reorder_point' => 'decimal:6',
            'reorder_quantity' => 'decimal:6',
            'nutrition' => 'array',
            'lock_version' => 'integer',
        ];
    }

    public function allergens(): HasMany
    {
        return $this->hasMany(EntityAllergen::class, 'entity_id')
            ->where('entity_type', 'stock_item');
    }

    /** Every BOM line that consumes this ingredient, across all versions. */
    public function recipeComponents(): HasMany
    {
        return $this->hasMany(RecipeComponent::class, 'component_id')
            ->where('component_type', 'stock_item');
    }
}
