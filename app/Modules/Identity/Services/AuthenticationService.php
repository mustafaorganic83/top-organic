<?php

namespace App\Modules\Identity\Services;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Contracts\DeviceRepository;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Contracts\SecurityPolicyRepository;
use App\Modules\Identity\Data\AuthenticationResult;
use App\Modules\Identity\Data\LoginData;
use App\Modules\Identity\Data\TokenPair;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    public function __construct(
        private readonly IdentityRepository $identities,
        private readonly DeviceRepository $devices,
        private readonly SecurityPolicyRepository $policies,
        private readonly IdentifierNormalizer $normalizer,
        private readonly AccountLockoutService $lockout,
        private readonly AuthSessionService $sessions,
        private readonly RefreshTokenService $refreshTokens,
        private readonly JwtAccessTokenService $accessTokens,
        private readonly PermissionResolver $permissions,
        private readonly RememberedDeviceService $rememberedDevices,
        private readonly MfaChallengeService $mfa,
        private readonly SecurityAuditService $audit,
    ) {}

    public function login(LoginData $data): AuthenticationResult
    {
        $identifier = $this->normalizer->normalize($data->identifier);
        $tenant = $this->identities->findActiveTenantBySlug(mb_strtolower(trim($data->tenantSlug)));
        if ($tenant === null) {
            Hash::check($data->password, $this->dummyHash());

            throw $this->invalidCredentials();
        }

        $user = $this->identities->findActiveUser($tenant, $identifier->column, $identifier->value);
        if ($user === null) {
            Hash::check($data->password, $this->dummyHash());
            $this->audit->record($tenant->getKey(), null, 'security', 'login.failed', [
                'result' => 'failure', 'reason' => 'invalid_credentials',
            ]);
            throw $this->invalidCredentials();
        }

        $policy = $this->policies->forTenant($tenant->getKey());
        $this->lockout->assertAvailable($user);
        if (! Hash::check($data->password, $user->password)) {
            $this->lockout->recordFailure($user, $policy);
            $this->audit->record($tenant->getKey(), null, 'security', 'login.failed', [
                'actor_id' => $user->getKey(), 'result' => 'failure', 'reason' => 'invalid_credentials',
            ]);
            throw $this->invalidCredentials();
        }

        $branch = $this->resolveBranch($user, $data->branch);
        $device = $this->resolveDevice($tenant, $branch, $data->device);
        if ($branch === null && $device?->branch_id !== null) {
            $branch = $this->resolveBranch($user, $device->branch_id);
        }

        $remembered = $device !== null && $data->rememberedDeviceToken !== null
            && $this->rememberedDevices->verify($user, $device, $data->rememberedDeviceToken);
        $requiresMfa = ($policy->mfa_required || $user->two_factor_enabled) && ! $remembered;
        $session = $this->sessions->create(
            $user, $branch, $device, $policy, ! $requiresMfa, $data->ipAddress, $data->userAgent,
        );
        $this->lockout->recordSuccess($user);

        if ($requiresMfa) {
            $method = $user->mfaMethods()->whereNotNull('verified_at')->whereNull('disabled_at')
                ->orderByDesc('is_primary')->first();
            $challenge = $this->mfa->create($user, $session, $method);

            return AuthenticationResult::mfaRequired($challenge->value, $challenge->id);
        }

        $this->auditLogin($user, $branch, $device, $session);

        return AuthenticationResult::authenticated($this->issueTokens($user, $session, $policy));
    }

    public function completeMfa(string $challengeToken, string $response): TokenPair
    {
        $session = $this->mfa->complete($challengeToken, $response);
        $user = $session->user;
        $policy = $this->policies->forTenant($session->tenant_id);
        $this->auditLogin($user, $session->branch, $session->device, $session);

        return $this->issueTokens($user, $session, $policy);
    }

    public function refresh(string $refreshToken): TokenPair
    {
        return $this->refreshTokens->rotatePair($refreshToken);
    }

    private function issueTokens(User $user, AuthSession $session, TenantSecurityPolicy $policy): TokenPair
    {
        $permissions = $this->permissions->resolve($user, $session->branch_id)->all();
        $access = $this->accessTokens->issue($user, $session, $policy->access_token_ttl_minutes, $permissions);
        $refresh = $this->refreshTokens->issue($session, $policy->refresh_token_ttl_minutes);

        return new TokenPair(
            $access->value, $refresh->value, $access->expiresAt,
            $refresh->expiresAt, $session->getKey(),
        );
    }

    private function resolveBranch(User $user, ?string $reference): ?Branch
    {
        $branch = $reference === null
            ? $this->identities->firstGrantedBranch($user)
            : $this->identities->findGrantedBranch($user, $reference);
        if ($reference !== null && $branch === null) {
            throw new IdentityException('BRANCH_ACCESS_DENIED', 403, 'The user is not granted access to the branch.');
        }

        return $branch;
    }

    private function resolveDevice(Tenant $tenant, ?Branch $branch, ?string $reference): ?Device
    {
        if ($reference === null) {
            if (config('identity.authentication.require_authorized_device', false)) {
                throw new IdentityException('DEVICE_REQUIRED', 422, 'An authorized device is required.');
            }

            return null;
        }

        $device = $this->devices->find($tenant, $reference);
        if ($device === null || $device->status !== 'authorized' || $device->revoked_at !== null
            || ($branch !== null && $device->branch_id !== null && $device->branch_id !== $branch->getKey())) {
            throw new IdentityException('DEVICE_NOT_AUTHORIZED', 403, 'The device is not authorized for this login.');
        }

        return $device;
    }

    private function auditLogin(User $user, ?Branch $branch, ?Device $device, AuthSession $session): void
    {
        $this->audit->record($user->tenant_id, $branch?->getKey(), 'security', 'login.succeeded', [
            'actor_id' => $user->getKey(), 'device_id' => $device?->getKey(),
            'auth_session_id' => $session->getKey(),
        ]);
    }

    private function invalidCredentials(): IdentityException
    {
        return new IdentityException('INVALID_CREDENTIALS', 401, 'The supplied credentials are invalid.');
    }

    private function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make('identity-invalid-credential-sentinel');
    }
}
