<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single bonus, penalty, allowance, or loan deduction line on a payslip.
 * Amount in integer minor units.
 */
class PayrollAdjustment extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
