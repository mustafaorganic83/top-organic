<?php

declare(strict_types=1);

namespace App\Models;

class SalesSequence extends TenantScopedModel
{
    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'next_value' => 'integer'];
    }
}
