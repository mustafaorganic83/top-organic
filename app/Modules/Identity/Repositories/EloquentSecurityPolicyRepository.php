<?php

namespace App\Modules\Identity\Repositories;

use App\Models\TenantSecurityPolicy;
use App\Modules\Identity\Contracts\SecurityPolicyRepository;

class EloquentSecurityPolicyRepository implements SecurityPolicyRepository
{
    public function forTenant(string $tenantId): TenantSecurityPolicy
    {
        $policy = TenantSecurityPolicy::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenantId]);

        return $policy->refresh();
    }
}
