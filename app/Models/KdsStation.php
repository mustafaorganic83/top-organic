<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KdsStation extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['sla_seconds' => 'integer', 'lock_version' => 'integer'];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(KdsTicket::class);
    }
}
