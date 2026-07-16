<?php

namespace App\Modules\Identity\Services;

use App\Models\AuthSession;
use App\Models\MfaChallenge;
use App\Models\MfaMethod;
use App\Models\MfaRecoveryCode;
use App\Models\User;
use App\Modules\Identity\Contracts\MfaMethodVerifier;
use App\Modules\Identity\Data\IssuedToken;
use App\Modules\Identity\Exceptions\IdentityException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MfaChallengeService
{
    /** @param array<int, MfaMethodVerifier> $verifiers */
    public function __construct(
        private readonly OpaqueTokenFactory $tokens,
        private readonly array $verifiers = [],
    ) {}

    public function create(User $user, AuthSession $session, ?MfaMethod $method = null): IssuedToken
    {
        $value = $this->tokens->make();
        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('identity.mfa.challenge_ttl_minutes', 5));
        $challenge = MfaChallenge::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->getKey(),
            'mfa_method_id' => $method?->getKey(),
            'auth_session_id' => $session->getKey(),
            'type' => $method?->type ?? 'recovery',
            'challenge_hash' => $this->tokens->hash($value),
            'expires_at' => $expiresAt,
        ]);

        return new IssuedToken($value, $expiresAt, $challenge->getKey());
    }

    public function complete(string $challengeToken, string $response): AuthSession
    {
        $result = DB::transaction(function () use ($challengeToken, $response): array {
            $challenge = MfaChallenge::withoutGlobalScopes()->with(['authSession.user', 'method'])
                ->where('challenge_hash', $this->tokens->hash($challengeToken))->lockForUpdate()->first();
            if ($challenge === null || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
                return ['error' => 'MFA_CHALLENGE_INVALID'];
            }

            $valid = $challenge->method === null
                ? $this->consumeRecoveryCode($challenge->user_id, $response)
                : $this->verifyMethod($challenge->method, $response);
            if (! $valid) {
                $challenge->increment('attempts');
                if ($challenge->attempts >= (int) config('identity.mfa.max_attempts', 5)) {
                    $challenge->forceFill(['consumed_at' => now()])->save();
                }

                return ['error' => 'MFA_RESPONSE_INVALID'];
            }

            $challenge->forceFill(['consumed_at' => now()])->save();
            $challenge->authSession->forceFill(['mfa_completed' => true])->save();

            return ['session' => $challenge->authSession->refresh()];
        });

        if (isset($result['error'])) {
            throw new IdentityException($result['error'], 401, 'The MFA challenge could not be completed.');
        }

        return $result['session'];
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(fn (): bool => $this->consumeRecoveryCode($user->getKey(), $code));
    }

    private function consumeRecoveryCode(int $userId, string $code): bool
    {
        $recovery = MfaRecoveryCode::withoutGlobalScopes()->where('user_id', $userId)
            ->where('code_hash', hash('sha256', $code))->whereNull('used_at')->lockForUpdate()->first();
        if ($recovery === null) {
            return false;
        }
        $recovery->forceFill(['used_at' => now()])->save();

        return true;
    }

    private function verifyMethod(MfaMethod $method, string $response): bool
    {
        foreach ($this->verifiers as $verifier) {
            if ($verifier->supports($method->type)) {
                return $verifier->verify($method, $response);
            }
        }

        throw new IdentityException('MFA_METHOD_UNAVAILABLE', 503, 'The MFA method is not configured.');
    }
}
