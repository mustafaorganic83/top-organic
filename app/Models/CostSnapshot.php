<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CostSnapshot extends TenantScopedModel
{
    use SoftDeletes;

    protected $table = 'cost_snapshots';

    protected $fillable = [
        'entity_type', 'entity_id', 'as_of_date', 'method',
        'unit_cost', 'currency_id', 'details',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'immutable_datetime',
            'unit_cost' => 'decimal:6',
            'details' => 'array',
        ];
    }

    // Relationships
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }

    // Scopes
    public function scopeForEntity($q, string $type, int|string $id) { return $q->where('entity_type', $type)->where('entity_id', $id); }
    public function scopeForMethod($q, string $method) { return $q->where('method', $method); }
    public function scopeAsOf($q, $at) { return $q->where('as_of_date', '<=', $at)->orderByDesc('as_of_date'); }
    public function scopeLatest($q) { return $q->orderByDesc('as_of_date'); }

    // Accessors
    protected function entityKey(): Attribute
    {
        return Attribute::make(get: fn () => $this->entity_type.'#'.$this->entity_id);
    }
}
