<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Support\Context\AppContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to any branch-owned model. Adds the global {@see BranchScope} and
 * auto-stamps `branch_id` from the resolved context on create. Branch is a
 * first-class scoping dimension from day one (architecture doc 03).
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('branch_id') === null) {
                $branchId = app(AppContext::class)->branchId();

                if ($branchId !== null) {
                    $model->setAttribute('branch_id', $branchId);
                }
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
