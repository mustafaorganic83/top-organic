<?php

declare(strict_types=1);

namespace App\Models;

class AutoPostingRule extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'debit_mapping' => 'array',
            'credit_mapping' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
