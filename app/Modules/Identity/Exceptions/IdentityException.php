<?php

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

class IdentityException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
