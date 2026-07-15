<?php

namespace App\Models;

use App\Models\Concerns\Immutable;

class SyncChangeLogEntry extends TenantScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['change_sequence' => 'integer', 'entity_revision' => 'integer', 'manifest' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
