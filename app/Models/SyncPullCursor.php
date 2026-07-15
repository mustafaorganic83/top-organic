<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncPullCursor extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['last_sequence' => 'integer', 'last_revision' => 'integer', 'last_pulled_at' => 'immutable_datetime', 'last_applied_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
