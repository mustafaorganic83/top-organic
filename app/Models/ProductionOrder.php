<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends BranchScopedModel
{
    use SoftDeletes;

    protected $table = 'production_orders';

    protected $fillable = [
        'branch_id', 'warehouse_id', 'prepared_recipe_id',
        'planned_qty', 'actual_qty', 'uom_id',
        'status', 'scheduled_at', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:6',
            'actual_qty' => 'decimal:6',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    // Relationships
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function preparedRecipe(): BelongsTo { return $this->belongsTo(SemiFinishedProduct::class, 'prepared_recipe_id'); }

    // Scopes
    public function scopeStatus($q, string $status) { return $q->where('status', $status); }
    public function scopeScheduledBetween($q, $from, $to) { return $q->whereBetween('scheduled_at', [$from, $to]); }
    public function scopeForWarehouse($q, int|string $warehouseId) { return $q->where('warehouse_id', $warehouseId); }
}
