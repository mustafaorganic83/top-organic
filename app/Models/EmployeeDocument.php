<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stored document attached to an employee (contract, ID, certificate, etc.)
 * with optional issue/expiry tracking. Branch-scoped.
 */
class EmployeeDocument extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'issued_date' => 'immutable_date',
            'expiry_date' => 'immutable_date',
            'lock_version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
