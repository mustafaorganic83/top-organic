<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModifierOption extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['surcharge_amount' => 'integer', 'sort_order' => 'integer', 'lock_version' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
