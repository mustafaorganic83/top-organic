<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable, versioned snapshot of a recipe: components, costed roll-up
 * (ingredient cost, recipe cost incl. waste, per yield unit), yield, waste and
 * nutrition. Lifecycle: draft -> published -> active -> archived.
 */
class RecipeVersion extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'yield_quantity' => 'decimal:6',
            'waste_bps' => 'integer',
            'ingredient_cost_amount' => 'integer',
            'recipe_cost_amount' => 'integer',
            'calories' => 'integer',
            'nutrition' => 'array',
            'published_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class);
    }
}
