<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled payment milestone for a PO or vendor contract.
 * Status lifecycle: pending → paid / overdue.
 */
class PaymentSchedule extends TenantScopedModel
{
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'integer',
            'paid_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendorContract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class);
    }
}
