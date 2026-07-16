<?php

namespace App\Modules\Identity\Services;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\Device;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Contracts\SessionRepository;
use App\Modules\Identity\Exceptions\IdentityException;

class AuthSessionService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly OpaqueTokenFactory $tokens,
    ) {}

    public function create(
        User $user,
        ?Branch $branch,
        ?Device $device,
        TenantSecurityPolicy $policy,
        bool $mfaCompleted,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuthSession {
        $user = User::withoutGlobalScopes()->find($user->getKey()) ?? $user;
        $policy = TenantSecurityPolicy::withoutGlobalScopes()->find($policy->getKey()) ?? $policy;
        $sessionKey = $this->tokens->make();

        return $this->sessions->createSession([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branch?->getKey(),
            'user_id' => $user->getKey(),
            'device_id' => $device?->getKey(),
            'session_key_hash' => $this->tokens->hash($sessionKey),
            'mfa_completed' => $mfaCompleted,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'password_version' => $user->password_version,
            'security_version' => $user->security_version,
            'authorization_version' => $user->authorization_version,
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes($policy->refresh_token_ttl_minutes),
        ]);
    }

    /** @param array<string, mixed> $claims */
    public function validate(AuthSession $session, User $user, array $claims = []): void
    {
        $valid = $session->user_id === $user->getKey()
            && $session->tenant_id === $user->tenant_id
            && $user->is_active
            && $user->account_status === 'active'
            && $session->revoked_at === null
            && $session->expires_at->isFuture()
            && $session->mfa_completed
            && $session->password_version === $user->password_version
            && $session->security_version === $user->security_version
            && $session->authorization_version === $user->authorization_version
            && ($session->device_id === null || ($session->device?->status === 'authorized' && $session->device->revoked_at === null));

        if ($claims !== []) {
            $valid = $valid
                && ($claims['tenant_id'] ?? null) === $session->tenant_id
                && ($claims['branch_id'] ?? null) === $session->branch_id
                && ($claims['device_id'] ?? null) === $session->device_id
                && (int) ($claims['password_version'] ?? 0) === $session->password_version
                && (int) ($claims['security_version'] ?? 0) === $session->security_version
                && (int) ($claims['authorization_version'] ?? 0) === $session->authorization_version;
        }

        if (! $valid) {
            throw new IdentityException('SESSION_INVALID', 401, 'The authentication session is no longer valid.');
        }
    }
}
