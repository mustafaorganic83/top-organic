<?php

namespace App\Models;

use App\Models\Concerns\Immutable;

class SalesIdempotencyRecord extends BranchScopedModel
{
    use Immutable;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['result_body' => 'array', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
