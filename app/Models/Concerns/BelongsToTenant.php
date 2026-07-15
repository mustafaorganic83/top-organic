<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Context\AppContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to any tenant-owned model. Adds the global {@see TenantScope} and
 * auto-stamps `tenant_id` from the resolved context on create, so a future
 * SaaS mode is an enablement rather than a migration (architecture doc 03).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $tenantId = app(AppContext::class)->tenantId();

                if ($tenantId !== null) {
                    $model->setAttribute('tenant_id', $tenantId);
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
