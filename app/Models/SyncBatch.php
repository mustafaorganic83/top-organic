<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncBatch extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['schema_version' => 'integer', 'operation_count' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(SyncOutboxOperation::class);
    }
}
