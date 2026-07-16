<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An image or video attached to a catalog entity (product, variant, category).
 * Polymorphic via entity_type/entity_id so a single gallery table serves the
 * whole menu.
 */
class MediaAsset extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
            'lock_version' => 'integer',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(null, 'entity_type', 'entity_id');
    }
}
