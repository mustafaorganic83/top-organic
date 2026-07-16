<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Attendance record for one employee on one work day. Captures GPS coordinates
 * and an optional photo at check-in/out, geo-fence verification, and computed
 * worked hours. Status lifecycle: checked_in -> checked_out.
 */
class Attendance extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'check_in_at' => 'immutable_datetime',
            'check_out_at' => 'immutable_datetime',
            'check_in_lat' => 'decimal:7',
            'check_in_lng' => 'decimal:7',
            'check_out_lat' => 'decimal:7',
            'check_out_lng' => 'decimal:7',
            'within_geofence' => 'boolean',
            'worked_hours' => 'decimal:2',
            'lock_version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }
}
