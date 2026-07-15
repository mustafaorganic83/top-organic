<?php

namespace App\Modules\Identity\Services;

use App\Models\AuthSession;
use App\Models\User;
use App\Modules\Identity\Contracts\IdentityRepository;
use App\Modules\Identity\Contracts\SessionRepository;
use App\Modules\Identity\Data\IssuedToken;
use App\Modules\Identity\Exceptions\IdentityException;
use Carbon\CarbonImmutable;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;

class JwtAccessTokenService
{
    public function __construct(
        private readonly JWTAuth $jwt,
        private readonly IdentityRepository $identities,
        private readonly SessionRepository $sessions,
        private readonly AuthSessionService $authSessions,
    ) {}

    /** @param array<int, string> $permissions */
    public function issue(User $user, AuthSession $session, int $ttlMinutes, array $permissions = []): IssuedToken
    {
        $factory = $this->jwt->factory();
        $previousTtl = $factory->getTTL();
        try {
            $factory->setTTL($ttlMinutes);
            $token = $this->jwt->claims([
                'aud' => config('identity.authentication.access_audience'),
                'session_id' => $session->getKey(),
                'tenant_id' => $session->tenant_id,
                'branch_id' => $session->branch_id,
                'device_id' => $session->device_id,
                'password_version' => $session->password_version,
                'security_version' => $session->security_version,
                'authorization_version' => $session->authorization_version,
                'permissions' => $permissions,
            ])->fromUser($user);
        } finally {
            $this->jwt->claims([]);
            $factory->setTTL($previousTtl);
        }

        return new IssuedToken($token, CarbonImmutable::now()->addMinutes($ttlMinutes), $session->getKey());
    }

    public function validate(string $token): User
    {
        try {
            $claims = $this->jwt->setToken($token)->getPayload()->toArray();
        } catch (JWTException) {
            throw new IdentityException('ACCESS_TOKEN_INVALID', 401, 'The access token is invalid or expired.');
        }

        if (! $this->hasAudience($claims['aud'] ?? null, config('identity.authentication.access_audience'))) {
            throw new IdentityException('ACCESS_TOKEN_INVALID', 401, 'The access token is invalid or expired.');
        }

        $user = $this->identities->findUserByPublicId((string) ($claims['sub'] ?? ''));
        $session = $this->sessions->findSession((string) ($claims['session_id'] ?? ''));
        if ($user === null || $session === null) {
            throw new IdentityException('ACCESS_TOKEN_INVALID', 401, 'The access token is invalid or expired.');
        }

        $this->authSessions->validate($session, $user, $claims);

        return $user;
    }

    private function hasAudience(mixed $claim, string $expected): bool
    {
        return is_array($claim) ? in_array($expected, $claim, true) : $claim === $expected;
    }
}
