<?php

namespace App\Reports\Support;

use Illuminate\Database\Eloquent\Builder;

class FilterApplier
{
    /** @param array<string,mixed> $filters */
    public static function apply(Builder $q, array $filters): Builder
    {
        if (!empty($filters['branch_id'])) {
            $q->where('branch_id', $filters['branch_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $q->where('warehouse_id', $filters['warehouse_id']);
        }
        if (!empty($filters['department_id'])) {
            $q->where('department_id', $filters['department_id']);
        }
        if (!empty($filters['recipe_id'])) {
            $q->where('recipe_id', $filters['recipe_id']);
        }
        if (!empty($filters['item_id'])) {
            $q->where(function ($qq) use ($filters) {
                $qq->where('item_id', $filters['item_id'])
                   ->orWhere('stock_item_id', $filters['item_id'])
                   ->orWhere('stockable_id', $filters['item_id']);
            });
        }
        if (!empty($filters['date_from'])) {
            $q->where(function ($qq) use ($filters) {
                foreach (['occurred_at','as_of_date','received_at','completed_at','effective_from'] as $col) {
                    $qq->orWhere($col, '>=', $filters['date_from']);
                }
            });
        }
        if (!empty($filters['date_to'])) {
            $q->where(function ($qq) use ($filters) {
                foreach (['occurred_at','as_of_date','received_at','completed_at','effective_from'] as $col) {
                    $qq->orWhere($col, '<=', $filters['date_to']);
                }
            });
        }
        return $q;
    }
}
