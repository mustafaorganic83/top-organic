<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Services\AuthSessionService;
use App\Modules\Identity\Services\JwtAccessTokenService;
use App\Support\Context\AppContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticatedContext
{
    public function __construct(
        private readonly AppContext $context,
        private readonly JWTAuth $jwt,
        private readonly AuthSessionService $sessions,
        private readonly IdentityRepository $identities,
        private readonly JwtAccessTokenService $accessTokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->forget();
        $token = $request->bearerToken();
        if ($token === null) {
            throw new AuthenticationException;
        }

        try {
            $user = $this->accessTokens->validate($token);
            $claims = $this->jwt->setToken($token)->getPayload()->toArray();
        } catch (Throwable) {
            throw new AuthenticationException;
        }

        auth('api')->setUser($user);

        $session = AuthSession::withoutGlobalScopes()->with(['device'])->find($claims['session_id'] ?? null);
        if ($session === null) {
            throw new IdentityException('SESSION_INVALID', 401, 'The authentication session is no longer valid.');
        }

        $this->sessions->validate($session, $user, $claims);
        $this->assertActiveGrant($user, $session);
        $this->context->setTenantId($session->tenant_id)
            ->setBranchId($session->branch_id)
            ->setDeviceId($session->device_id);
        $request->attributes->set('auth_session', $session);

        return $next($request);
    }

    private function assertActiveGrant(User $user, AuthSession $session): void
    {
        $tenantActive = Tenant::query()->whereKey($session->tenant_id)->where('is_active', true)->exists();
        if (! $tenantActive || $user->tenant_id !== $session->tenant_id) {
            throw new IdentityException('TENANT_ACCESS_DENIED', 403, 'The tenant grant is no longer active.');
        }

        if ($session->branch_id !== null) {
            $branch = $this->identities->findGrantedBranch($user, $session->branch_id);
            if (! $branch instanceof Branch || $branch->tenant_id !== $session->tenant_id) {
                throw new IdentityException('BRANCH_ACCESS_DENIED', 403, 'The branch grant is no longer active.');
            }
        }

        if ($session->device_id !== null) {
            $device = $session->device;
            $valid = $device !== null && $device->tenant_id === $session->tenant_id
                && $device->status === 'authorized' && $device->revoked_at === null
                && ($device->branch_id === null || $device->branch_id === $session->branch_id);
            if (! $valid) {
                throw new IdentityException('DEVICE_NOT_AUTHORIZED', 403, 'The device grant is no longer active.');
            }
        }
    }
}
