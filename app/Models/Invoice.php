<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends BranchScopedModel
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'customer_snapshot' => 'array', 'subtotal_amount' => 'integer', 'discount_amount' => 'integer', 'charge_amount' => 'integer', 'tax_amount' => 'integer', 'tip_amount' => 'integer', 'rounding_amount' => 'integer', 'total_amount' => 'integer', 'policy_revision' => 'integer', 'issued_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_number');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(InvoiceTaxLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }
}
