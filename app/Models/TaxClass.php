<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class TaxClass extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['rate_bps' => 'integer', 'is_inclusive' => 'boolean', 'effective_from' => 'immutable_datetime', 'effective_to' => 'immutable_datetime', 'lock_version' => 'integer'];
    }
}
