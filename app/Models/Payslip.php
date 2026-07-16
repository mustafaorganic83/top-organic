<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Salary slip for one employee within a payroll run. Bonuses, penalties, and
 * loan deductions roll up from payroll adjustments. Amounts in minor units.
 */
class Payslip extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'base_amount' => 'integer',
            'bonus_amount' => 'integer',
            'penalty_amount' => 'integer',
            'loan_deduction_amount' => 'integer',
            'gross_amount' => 'integer',
            'deductions_amount' => 'integer',
            'net_amount' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }
}
