<?php

namespace App\Models;

/**
 * Attendance geo-fence: a circular boundary (center + radius) used to verify
 * that a check-in/check-out happened on-site. Branch-scoped.
 */
class Geofence extends BranchScopedModel
{
    protected $table = 'geofences';

    protected function casts(): array
    {
        return [
            'center_lat' => 'decimal:7',
            'center_lng' => 'decimal:7',
            'radius_meters' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
