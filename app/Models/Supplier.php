<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends TenantScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
        ];
    }

    public function apAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ap_account_id');
    }

    public function apInvoices(): HasMany
    {
        return $this->hasMany(ApInvoice::class);
    }

    public function apPayments(): HasMany
    {
        return $this->hasMany(ApPayment::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(SupplierEvaluation::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function vendorContracts(): HasMany
    {
        return $this->hasMany(VendorContract::class);
    }

    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }
}
