<?php

namespace App\Models;

use App\Models\Concerns\Immutable;

class SyncTombstone extends TenantScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['deletion_revision' => 'integer', 'change_sequence' => 'integer', 'deleted_at' => 'immutable_datetime', 'retention_until' => 'immutable_datetime'];
    }
}
