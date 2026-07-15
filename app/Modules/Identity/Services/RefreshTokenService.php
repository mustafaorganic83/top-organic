<?php

namespace App\Modules\Identity\Services;

use App\Models\AuthSession;
use App\Models\Tenant;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Contracts\SecurityPolicyRepository;
use App\Modules\Identity\Contracts\SessionRepository;
use App\Modules\Identity\Data\IssuedToken;
use App\Modules\Identity\Data\TokenPair;
use App\Modules\Identity\Exceptions\IdentityException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefreshTokenService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly AuthSessionService $authSessions,
        private readonly OpaqueTokenFactory $tokens,
        private readonly JwtAccessTokenService $accessTokens,
        private readonly PermissionResolver $permissions,
        private readonly SecurityPolicyRepository $policies,
        private readonly IdentityRepository $identities,
    ) {}

    public function issue(AuthSession $session, int $ttlMinutes): IssuedToken
    {
        $value = $this->tokens->make();
        $expiresAt = CarbonImmutable::now()->addMinutes($ttlMinutes);
        $token = $this->sessions->createRefreshToken([
            'auth_session_id' => $session->getKey(),
            'family_id' => strtolower((string) Str::ulid()),
            'token_hash' => $this->tokens->hash($value),
            'expires_at' => $expiresAt,
        ]);

        return new IssuedToken($value, $expiresAt, $token->getKey());
    }

    public function rotate(string $value): IssuedToken
    {
        $result = DB::transaction(function () use ($value): array {
            $current = $this->sessions->lockRefreshToken($this->tokens->hash($value));
            if ($current === null) {
                return ['error' => 'REFRESH_TOKEN_INVALID'];
            }

            $session = $current->authSession;
            if ($current->used_at !== null || $current->revoked_at !== null) {
                $this->sessions->revokeRefreshFamily($current->family_id);
                $this->sessions->revokeSession($session, 'refresh_token_reuse');

                return ['error' => 'REFRESH_TOKEN_REUSED'];
            }
            if ($current->expires_at->isPast()) {
                $current->forceFill(['revoked_at' => now()]);
                $this->sessions->saveRefreshToken($current);

                return ['error' => 'REFRESH_TOKEN_EXPIRED'];
            }

            $this->authSessions->validate($session, $session->user);
            $this->assertActiveScope($session);
            $replacementValue = $this->tokens->make();
            $replacement = $this->sessions->createRefreshToken([
                'auth_session_id' => $session->getKey(),
                'family_id' => $current->family_id,
                'parent_token_id' => $current->getKey(),
                'token_hash' => $this->tokens->hash($replacementValue),
                'expires_at' => $current->expires_at,
            ]);
            $current->forceFill(['used_at' => now(), 'replaced_by_token_id' => $replacement->getKey()]);
            $this->sessions->saveRefreshToken($current);

            return ['token' => new IssuedToken(
                $replacementValue,
                CarbonImmutable::instance($replacement->expires_at),
                $replacement->getKey(),
            )];
        }, 3);

        if (isset($result['error'])) {
            $status = $result['error'] === 'REFRESH_TOKEN_REUSED' ? 409 : 401;
            throw new IdentityException($result['error'], $status, 'The refresh token cannot be used.');
        }

        return $result['token'];
    }

    public function rotatePair(string $value, ?int $accessTtlMinutes = null): TokenPair
    {
        $refresh = $this->rotate($value);
        $record = $this->sessions->findRefreshToken($refresh->id);
        if ($record === null) {
            throw new IdentityException('REFRESH_TOKEN_INVALID', 401, 'The refresh token cannot be used.');
        }

        $session = $record->authSession;
        $user = $session->user;
        $accessTtlMinutes ??= $this->policies->forTenant($session->tenant_id)->access_token_ttl_minutes;
        $access = $this->accessTokens->issue(
            $user,
            $session,
            $accessTtlMinutes,
            $this->permissions->resolve($user, $session->branch_id)->all(),
        );

        return new TokenPair(
            $access->value,
            $refresh->value,
            $access->expiresAt,
            $refresh->expiresAt,
            $session->getKey(),
        );
    }

    private function assertActiveScope(AuthSession $session): void
    {
        $tenantActive = Tenant::query()->whereKey($session->tenant_id)->where('is_active', true)->exists();
        $branchActive = $session->branch_id === null
            || $this->identities->findGrantedBranch($session->user, $session->branch_id) !== null;
        if (! $tenantActive || ! $branchActive) {
            throw new IdentityException('SESSION_INVALID', 401, 'The authentication session is no longer valid.');
        }
    }
}
