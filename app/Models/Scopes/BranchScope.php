<?php

namespace App\Models\Scopes;

use App\Support\Context\AppContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Automatically constrains queries to the resolved branch (architecture
 * doc 03 "every transactional row carries branch_id; global query scopes
 * enforce it"). A no-op when no branch is resolved so console/seed/test
 * bootstrap paths are not blocked.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(AppContext::class);

        if ($context->hasBranch()) {
            $builder->where(
                $model->qualifyColumn('branch_id'),
                $context->branchId(),
            );
        }
    }
}
