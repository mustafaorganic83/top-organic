<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashDrawer extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashDrawerSession::class);
    }
}
