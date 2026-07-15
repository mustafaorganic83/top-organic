<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SyncOutboxOperation extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['payload_version' => 'integer', 'payload' => 'array', 'device_sequence' => 'integer', 'logical_clock' => 'integer', 'attempt_count' => 'integer', 'next_attempt_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'sync_batch_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function conflict(): HasOne
    {
        return $this->hasOne(SyncConflict::class);
    }
}
