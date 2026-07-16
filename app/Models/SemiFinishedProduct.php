<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A semi-finished product (sauce, dough, stock) produced from its own recipe
 * and consumed by other recipes. Stockable and costable like an ingredient.
 */
class SemiFinishedProduct extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'yield_quantity' => 'decimal:6',
            'calories_per_unit' => 'integer',
            'nutrition' => 'array',
            'lock_version' => 'integer',
        ];
    }

    public function recipe(): MorphOne
    {
        return $this->morphOne(Recipe::class, 'owner', 'owner_type', 'owner_id');
    }
}
