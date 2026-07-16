<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Payroll run for a branch over a pay period. Status lifecycle:
 * draft -> calculated -> approved -> paid. Total in integer minor units.
 */
class PayrollRun extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'total_amount' => 'integer',
            'calculated_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
