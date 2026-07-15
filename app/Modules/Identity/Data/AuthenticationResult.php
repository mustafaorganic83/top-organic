<?php

namespace App\Modules\Identity\Data;

final readonly class AuthenticationResult
{
    private function __construct(
        public bool $mfaRequired,
        public ?TokenPair $tokens,
        public ?string $mfaChallenge,
        public ?string $mfaChallengeId,
    ) {}

    public static function authenticated(TokenPair $tokens): self
    {
        return new self(false, $tokens, null, null);
    }

    public static function mfaRequired(string $challenge, string $challengeId): self
    {
        return new self(true, null, $challenge, $challengeId);
    }
}
