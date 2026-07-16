<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic tag linking an allergen to a catalog/stock entity. is_traces
 * distinguishes a direct ingredient from a "may contain traces of" warning.
 */
class EntityAllergen extends TenantScopedModel
{
    protected function casts(): array
    {
        return ['is_traces' => 'boolean'];
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(null, 'entity_type', 'entity_id');
    }
}
