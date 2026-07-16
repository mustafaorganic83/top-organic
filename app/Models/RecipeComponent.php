<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single BOM line on a recipe version: how much of a stock item or a
 * semi-finished product the batch consumes, with per-line waste and a cost
 * snapshot captured at publish time.
 */
class RecipeComponent extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'waste_bps' => 'integer',
            'unit_cost_amount' => 'integer',
            'line_cost_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'recipe_version_id');
    }

    public function component(): MorphTo
    {
        return $this->morphTo(null, 'component_type', 'component_id');
    }
}
