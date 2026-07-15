<?php

namespace App\Modules\Identity\Contracts;

use App\Models\TenantSecurityPolicy;

interface SecurityPolicyRepository
{
    public function forTenant(string $tenantId): TenantSecurityPolicy;
}
