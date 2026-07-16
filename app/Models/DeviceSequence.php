<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSequence extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['next_sequence' => 'integer', 'logical_clock' => 'integer', 'last_acknowledged_sequence' => 'integer', 'lock_version' => 'integer'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
