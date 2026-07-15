<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiningTable extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'sort_order' => 'integer', 'lock_version' => 'integer'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }
}
