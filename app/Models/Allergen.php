<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant-defined allergen (gluten, nuts, dairy, ...) that can be tagged onto
 * products, stock items and semi-finished products via entity_allergens.
 */
class Allergen extends TenantScopedModel
{
    public function tags(): HasMany
    {
        return $this->hasMany(EntityAllergen::class);
    }
}
