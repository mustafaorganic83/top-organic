<?php

declare(strict_types=1);

namespace App\Modules\Tables\Exceptions;

use RuntimeException;

final class ReservationException extends RuntimeException
{
    public const INVALID_INPUT = 'RESERVATION_INVALID_INPUT';

    public const SCOPE_VIOLATION = 'RESERVATION_SCOPE_VIOLATION';

    public const NOT_FOUND = 'RESERVATION_NOT_FOUND';

    public const INVALID_STATE = 'RESERVATION_INVALID_STATE';

    public const STALE_VERSION = 'RESERVATION_STALE_VERSION';

    public const TABLE_UNAVAILABLE = 'RESERVATION_TABLE_UNAVAILABLE';

    public const CAPACITY_EXCEEDED = 'RESERVATION_CAPACITY_EXCEEDED';

    public const IN_USE = 'RESERVATION_ENTITY_IN_USE';

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
