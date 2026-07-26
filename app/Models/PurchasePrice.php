<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchasePrice extends TenantScopedModel
{
    use SoftDeletes;

    protected $table = 'purchase_prices';

    protected $fillable = [
        'item_id', 'supplier_id', 'uom_id',
        'price', 'currency_id', 'effective_from', 'effective_to', 'source',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:6',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    // Relationships
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    // Scopes
    public function scopeOfItem($q, int|string $itemId)
    {
        return $q->where('item_id', $itemId);
    }

    public function scopeOfSupplier($q, int|string $supplierId)
    {
        return $q->where('supplier_id', $supplierId);
    }

    public function scopeEffectiveAt($q, \DateTimeInterface|string $at)
    {
        return $q->where('effective_from', '<=', $at)
                 ->where(function ($w) use ($at) {
                     $w->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
                 })
                 ->orderByDesc('effective_from');
    }

    public function scopeLatestFirst($q)
    {
        return $q->orderByDesc('effective_from');
    }
}
