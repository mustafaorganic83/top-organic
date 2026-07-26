<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Waste extends BranchScopedModel
{
    use SoftDeletes;

    protected $table = 'waste';

    protected $fillable = [
        'waste_type', 'production_order_id', 'item_id', 'qty', 'uom_id', 'pct',
        'department_id', 'warehouse_id', 'reason', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'pct' => 'decimal:6',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    // Relationships
    public function productionOrder(): BelongsTo { return $this->belongsTo(ProductionOrder::class); }
    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }

    // Scopes
    public function scopeBetweenDates($q, $from, $to) { return $q->whereBetween('occurred_at', [$from, $to]); }
    public function scopeOfType($q, string $type) { return $q->where('waste_type', $type); }
    public function scopeForDepartment($q, int|string $deptId) { return $q->where('department_id', $deptId); }
}
