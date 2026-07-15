<?php

namespace App\Modules\Identity\Data;

use Carbon\CarbonImmutable;

final readonly class IssuedToken
{
    public function __construct(
        public string $value,
        public CarbonImmutable $expiresAt,
        public string $id,
    ) {}
}
