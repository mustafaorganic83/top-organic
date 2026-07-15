<?php

namespace App\Modules\Identity\Data;

use Carbon\CarbonImmutable;

final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $accessTokenExpiresAt,
        public CarbonImmutable $refreshTokenExpiresAt,
        public string $authSessionId,
        public string $tokenType = 'Bearer',
    ) {}
}
