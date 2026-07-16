<?php

namespace App\Modules\Identity\Services;

class OpaqueTokenFactory
{
    public function make(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
