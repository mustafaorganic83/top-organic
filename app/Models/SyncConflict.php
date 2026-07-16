<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncConflict extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['local_revision' => 'integer', 'remote_revision' => 'integer', 'local_snapshot' => 'array', 'remote_snapshot' => 'array', 'resolved_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(SyncOutboxOperation::class, 'sync_outbox_operation_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
