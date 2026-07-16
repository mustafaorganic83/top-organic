<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Exceptions;

use RuntimeException;

final class KitchenException extends RuntimeException
{
    public const INVALID_INPUT = 'KITCHEN_INVALID_INPUT';

    public const SCOPE_VIOLATION = 'KITCHEN_SCOPE_VIOLATION';

    public const NOT_FOUND = 'KITCHEN_NOT_FOUND';

    public const INVALID_STATE = 'KITCHEN_INVALID_STATE';

    public const STALE_VERSION = 'KITCHEN_STALE_VERSION';

    public const IN_USE = 'KITCHEN_ENTITY_IN_USE';

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $context */
    public static function invalid(string $message, array $context = []): self
    {
        return new self(self::INVALID_INPUT, 422, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function conflict(string $code, string $message, array $context = []): self
    {
        return new self($code, 409, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function notFound(string $message, array $context = []): self
    {
        return new self(self::NOT_FOUND, 404, $message, $context);
    }
}
