<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Support\Context\AppContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant/branch context for the session-authenticated back-office UI. The API
 * counterpart ({@see AuthenticatedContext}) derives scope from the JWT auth
 * session; here it comes from the signed-in user's tenant and their granted
 * branches. The active branch is remembered in the session so a chain-level
 * user can switch without re-authenticating. The resolved user is also bound
 * to the api guard so the shared permission middleware applies unchanged.
 */
class WebSessionContext
{
    public const BRANCH_SESSION_KEY = 'active_branch_id';

    public function __construct(
        private readonly AppContext $context,
        private readonly IdentityRepository $identities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        if (! $user instanceof User) {
            throw new AuthenticationException(guards: ['web']);
        }

        $branch = $this->resolveBranch($request, $user);
        if (! $branch instanceof Branch) {
            throw new IdentityException('BRANCH_ACCESS_DENIED', 403,
                'No active branch has been granted to this account.');
        }

        auth('api')->setUser($user);
        $this->context->forget();
        $this->context->setTenantId($user->tenant_id)->setBranchId($branch->getKey());
        $request->session()->put(self::BRANCH_SESSION_KEY, $branch->getKey());
        $request->attributes->set('active_branch', $branch);

        return $next($request);
    }

    /**
     * The branch the session is pinned to, falling back to the first branch
     * granted to the user. A pinned branch that is no longer granted is
     * discarded rather than trusted.
     */
    private function resolveBranch(Request $request, User $user): ?Branch
    {
        $pinned = $request->session()->get(self::BRANCH_SESSION_KEY);

        return (is_string($pinned) ? $this->identities->findGrantedBranch($user, $pinned) : null)
            ?? $this->identities->firstGrantedBranch($user);
    }
}
