<?php

namespace App\Modules\Identity\Services;

use App\Models\Branch;
use App\Models\Device;
use App\Models\OfflineLoginGrant;
use App\Models\OfflineLoginReceipt;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Modules\Identity\Contracts\SecurityEventRepository;
use App\Modules\Identity\Data\IssuedToken;
use App\Modules\Identity\Exceptions\IdentityException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Throwable;

class OfflineLoginService
{
    public function __construct(
        private readonly SecurityEventRepository $events,
        private readonly PermissionResolver $permissions,
        private readonly OpaqueTokenFactory $tokens,
        private readonly SecurityAuditService $audit,
        private readonly JWTAuth $jwt,
    ) {}

    public function issue(
        User $user,
        Branch $branch,
        Device $device,
        TenantSecurityPolicy $policy,
    ): IssuedToken {
        $user = User::withoutGlobalScopes()->find($user->getKey()) ?? $user;
        $policy = TenantSecurityPolicy::withoutGlobalScopes()->find($policy->getKey()) ?? $policy;
        if (! $policy->allow_offline_login || $user->tenant_id !== $branch->tenant_id
            || $device->tenant_id !== $user->tenant_id || $device->branch_id !== $branch->getKey()
            || $device->status !== 'authorized' || $device->revoked_at !== null) {
            throw new IdentityException('OFFLINE_LOGIN_NOT_ALLOWED', 403, 'Offline login is not allowed for this account and device.');
        }

        $expiresAt = CarbonImmutable::now()->addHours($policy->offline_login_hours);
        $grantId = strtolower((string) Str::ulid());
        $permissions = $this->permissions->resolve($user, $branch)->all();
        $value = $this->signedGrant($grantId, $user, $branch, $device, $permissions, $policy->offline_login_hours);
        $grant = $this->events->createOfflineGrant([
            'id' => $grantId,
            'tenant_id' => $user->tenant_id,
            'branch_id' => $branch->getKey(),
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            'grant_token_hash' => $this->tokens->hash($value),
            'permission_snapshot' => $permissions,
            'password_version' => $user->password_version,
            'security_version' => $user->security_version,
            'authorization_version' => $user->authorization_version,
            'issued_at' => now(),
            'expires_at' => $expiresAt,
        ]);
        $this->audit->record($user->tenant_id, $branch->getKey(), 'security', 'offline_grant.issued', [
            'actor_id' => $user->getKey(), 'device_id' => $device->getKey(),
            'target_type' => OfflineLoginGrant::class, 'target_id' => $grant->getKey(),
        ]);

        return new IssuedToken($value, $expiresAt, $grant->getKey());
    }

    public function validate(string $value): OfflineLoginGrant
    {
        $result = DB::transaction(function () use ($value): array {
            try {
                $claims = $this->jwt->setToken($value)->getPayload()->toArray();
            } catch (TokenExpiredException) {
                return ['error' => 'OFFLINE_GRANT_EXPIRED'];
            } catch (Throwable) {
                return ['error' => 'OFFLINE_GRANT_INVALID'];
            }

            $grant = $this->events->lockOfflineGrant($this->tokens->hash($value));
            if ($grant === null) {
                return ['error' => 'OFFLINE_GRANT_INVALID'];
            }

            $valid = $grant->revoked_at === null && $grant->expires_at->isFuture()
                && $this->hasAudience($claims['aud'] ?? null, config('identity.authentication.offline_audience'))
                && ($claims['grant_id'] ?? null) === $grant->getKey()
                && ($claims['sub'] ?? null) === $grant->user->public_id
                && ($claims['tenant_id'] ?? null) === $grant->tenant_id
                && ($claims['branch_id'] ?? null) === $grant->branch_id
                && ($claims['device_id'] ?? null) === $grant->device_id
                && $grant->device->status === 'authorized' && $grant->device->revoked_at === null
                && $grant->user->is_active && $grant->user->account_status === 'active'
                && $grant->password_version === $grant->user->password_version
                && $grant->security_version === $grant->user->security_version
                && $grant->authorization_version === $grant->user->authorization_version;
            if (! $valid) {
                return ['error' => 'OFFLINE_GRANT_EXPIRED'];
            }
            $grant->forceFill(['last_used_at' => now()])->save();

            return ['grant' => $grant];
        });

        if (isset($result['error'])) {
            throw new IdentityException($result['error'], 401, 'The offline login grant is invalid or expired.');
        }

        return $result['grant'];
    }

    /** @param array<int, string> $permissions */
    private function signedGrant(
        string $grantId,
        User $user,
        Branch $branch,
        Device $device,
        array $permissions,
        int $ttlHours,
    ): string {
        $factory = $this->jwt->factory();
        $previousTtl = $factory->getTTL();

        try {
            $factory->setTTL($ttlHours * 60);

            return $this->jwt->claims([
                'aud' => config('identity.authentication.offline_audience'),
                'session_id' => null,
                'grant_id' => $grantId,
                'tenant_id' => $user->tenant_id,
                'branch_id' => $branch->getKey(),
                'device_id' => $device->getKey(),
                'permissions' => array_values($permissions),
                'password_version' => $user->password_version,
                'security_version' => $user->security_version,
                'authorization_version' => $user->authorization_version,
            ])->fromUser($user);
        } finally {
            $this->jwt->claims([]);
            $factory->setTTL($previousTtl);
        }
    }

    private function hasAudience(mixed $claim, string $expected): bool
    {
        return is_array($claim) ? in_array($expected, $claim, true) : $claim === $expected;
    }

    /** @return Collection<int, OfflineLoginGrant> */
    public function list(User $user): Collection
    {
        return OfflineLoginGrant::withoutGlobalScopes()->where('user_id', $user->getKey())
            ->where('tenant_id', $user->tenant_id)->latest('issued_at')->get();
    }

    public function revoke(User $user, OfflineLoginGrant $grant, string $reason = 'user_revoked'): void
    {
        if ($grant->tenant_id !== $user->tenant_id || $grant->user_id !== $user->getKey()) {
            throw new IdentityException('OFFLINE_GRANT_NOT_FOUND', 404, 'The offline grant was not found.');
        }

        if ($grant->revoked_at === null) {
            $grant->forceFill(['revoked_at' => now(), 'revocation_reason' => $reason])->save();
            $this->audit->record($grant->tenant_id, $grant->branch_id, 'security', 'offline_grant.revoked', [
                'actor_id' => $user->getKey(), 'device_id' => $grant->device_id,
                'target_type' => OfflineLoginGrant::class, 'target_id' => $grant->getKey(), 'reason' => $reason,
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    public function ingestReceipt(
        OfflineLoginGrant $grant,
        string $clientReceiptId,
        string $result,
        DateTimeInterface $occurredAt,
        array $metadata = [],
        ?string $ipAddress = null,
    ): OfflineLoginReceipt {
        return $this->events->firstOrCreateReceipt([
            'offline_login_grant_id' => $grant->getKey(),
            'tenant_id' => $grant->tenant_id,
            'branch_id' => $grant->branch_id,
            'user_id' => $grant->user_id,
            'device_id' => $grant->device_id,
            'client_receipt_id' => $clientReceiptId,
            'result' => $result,
            'ip_address' => $ipAddress,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
            'synced_at' => now(),
        ]);
    }
}
