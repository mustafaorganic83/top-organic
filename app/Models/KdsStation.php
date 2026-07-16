<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KdsStation extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['sla_seconds' => 'integer', 'default_prep_seconds' => 'integer', 'sort_order' => 'integer',
            'screen_config' => 'array', 'lock_version' => 'integer'];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(KdsTicket::class);
    }
}
