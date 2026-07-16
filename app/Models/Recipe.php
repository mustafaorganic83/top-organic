<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Recipe header owned by exactly one producible (a sellable product variant or
 * a semi-finished product). The costed BOM and nutrition live in versions; the
 * currently live one is pinned via active_version_id.
 */
class Recipe extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo(null, 'owner_type', 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'active_version_id');
    }
}
