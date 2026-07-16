<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A branch-scoped storage location (main store, walk-in fridge, bar). Stock
 * levels and batches are held per warehouse, so a branch can hold the same
 * ingredient across several locations with independent on-hand and costing.
 */
class Warehouse extends BranchScopedModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_sellable_source' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
