<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends BranchScopedModel
{
    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'integer', 'discount_amount' => 'integer', 'charge_amount' => 'integer',
            'tax_amount' => 'integer', 'tip_amount' => 'integer', 'rounding_amount' => 'integer',
            'total_amount' => 'integer', 'paid_amount' => 'integer', 'due_amount' => 'integer',
            'customer_snapshot' => 'array', 'policy_snapshot' => 'array', 'business_date' => 'immutable_date',
            'placed_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(OrderCharge::class);
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(OrderTaxLine::class);
    }

    public function tips(): HasMany
    {
        return $this->hasMany(OrderTip::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(DeliveryFulfillment::class);
    }
}
