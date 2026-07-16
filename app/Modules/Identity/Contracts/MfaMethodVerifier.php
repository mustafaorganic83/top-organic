<?php

namespace App\Modules\Identity\Contracts;

use App\Models\MfaMethod;

interface MfaMethodVerifier
{
    public function supports(string $type): bool;

    public function verify(MfaMethod $method, string $response): bool;
}
