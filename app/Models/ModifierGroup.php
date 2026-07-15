<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModifierGroup extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['min_selections' => 'integer', 'max_selections' => 'integer', 'is_required' => 'boolean', 'lock_version' => 'integer'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class);
    }
}
