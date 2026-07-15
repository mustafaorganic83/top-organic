<?php

namespace App\Models\Scopes;

use App\Support\Context\AppContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Automatically constrains queries to the resolved tenant (architecture
 * doc 02 "Global query scoping"). When no tenant is resolved (console,
 * seeding, tests without context) the scope is a no-op so bootstrap paths
 * are not blocked; request paths always resolve a tenant via middleware.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(AppContext::class);

        if ($context->hasTenant()) {
            $builder->where(
                $model->qualifyColumn('tenant_id'),
                $context->tenantId(),
            );
        }
    }
}
