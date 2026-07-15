<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintRoute extends BranchScopedModel
{
    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_active' => 'boolean', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KdsStation::class, 'kds_station_id');
    }
}
