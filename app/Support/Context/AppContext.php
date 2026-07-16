<?php

namespace App\Support\Context;

/**
 * Request-scoped context holder for the resolved tenant and branch.
 *
 * Branch is a first-class scoping dimension today; tenant is the future
 * multi-tenant SaaS dimension that already threads through the stack
 * (architecture doc 03). Global query scopes read this context so that no
 * query can silently leak across a branch or tenant boundary.
 *
 * Registered as a singleton so services, middleware, formatters, and query
 * scopes all share one resolved context per request/job.
 */
class AppContext
{
    private ?string $tenantId = null;

    private ?string $branchId = null;

    private ?string $deviceId = null;

    public function setTenantId(?string $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function setBranchId(?string $branchId): self
    {
        $this->branchId = $branchId;

        return $this;
    }

    public function branchId(): ?string
    {
        return $this->branchId;
    }

    public function hasBranch(): bool
    {
        return $this->branchId !== null;
    }

    public function setDeviceId(?string $deviceId): self
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function deviceId(): ?string
    {
        return $this->deviceId;
    }

    /**
     * Clear all resolved context. Primarily used between queued jobs so a
     * worker never carries one job's tenant/branch into the next.
     */
    public function forget(): void
    {
        $this->tenantId = null;
        $this->branchId = null;
        $this->deviceId = null;
    }
}
