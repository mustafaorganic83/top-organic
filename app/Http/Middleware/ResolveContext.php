<?php

namespace App\Http\Middleware;

use App\Support\Context\AppContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves tenant + branch (+ device) context on every request and injects
 * it into the request-scoped {@see AppContext} used by services and global
 * query scopes (architecture doc 02 "Context resolution middleware").
 *
 * Resolution order:
 *   1. Explicit request headers (X-Tenant-Id / X-Branch-Id / X-Device-Id).
 *   2. The authenticated user's tenant and first granted branch.
 *
 * This is the foundation wiring; per-request enforcement of grants is layered
 * on top as the Identity module is built out.
 */
class ResolveContext
{
    public function __construct(private readonly AppContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $tenantId = $request->header('X-Tenant-Id')
            ?? $user?->getAttribute('tenant_id');

        $branchId = $request->header('X-Branch-Id')
            ?? $user?->branches()->value('branches.id');

        $this->context
            ->setTenantId($tenantId ? (string) $tenantId : null)
            ->setBranchId($branchId ? (string) $branchId : null)
            ->setDeviceId($request->header('X-Device-Id'));

        return $next($request);
    }
}
