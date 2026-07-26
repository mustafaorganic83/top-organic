<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CostHistory extends TenantScopedModel
{
    use SoftDeletes;

    protected $table = 'cost_history';

    protected $fillable = [
        'entity_type', 'entity_id', 'method',
        'unit_cost', 'currency_id', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:6',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }

    // Scopes
    public function scopeForEntity($q, string $type, int|string $id) { return $q->where('entity_type', $type)->where('entity_id', $id); }
    public function scopeForMethod($q, string $method) { return $q->where('method', $method); }
    public function scopeEffectiveAt($q, $at)
    {
        return $q->where('effective_from', '<=', $at)
                 ->where(function ($w) use ($at) {
                     $w->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
                 })
                 ->orderByDesc('effective_from');
    }
}
