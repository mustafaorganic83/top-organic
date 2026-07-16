<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Salary advance or long-term loan against an employee. Amounts in integer
 * minor units. Status lifecycle:
 * requested -> approved -> active -> settled / rejected.
 */
class EmployeeLoan extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'principal_amount' => 'integer',
            'outstanding_amount' => 'integer',
            'installment_amount' => 'integer',
            'installments_count' => 'integer',
            'approved_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
