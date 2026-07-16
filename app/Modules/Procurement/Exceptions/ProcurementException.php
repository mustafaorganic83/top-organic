<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Exceptions;

use RuntimeException;

final class ProcurementException extends RuntimeException
{
    public const INVALID_INPUT = 'PROCUREMENT_INVALID_INPUT';

    public const SCOPE_VIOLATION = 'PROCUREMENT_SCOPE_VIOLATION';

    public const NOT_FOUND = 'PROCUREMENT_NOT_FOUND';

    public const INVALID_STATE = 'PROCUREMENT_INVALID_STATE';

    public const STALE_VERSION = 'PROCUREMENT_STALE_VERSION';

    public const IN_USE = 'PROCUREMENT_ENTITY_IN_USE';

    public const ALREADY_APPROVED = 'PROCUREMENT_ALREADY_APPROVED';

    public const INVALID_TRANSITION = 'PROCUREMENT_INVALID_TRANSITION';

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
    public static function invalidState(string $message, array $context = []): self
    {
        return new self(self::INVALID_STATE, 422, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function notFound(string $message, array $context = []): self
    {
        return new self(self::NOT_FOUND, 404, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function alreadyApproved(string $message, array $context = []): self
    {
        return new self(self::ALREADY_APPROVED, 409, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function invalidTransition(string $from, string $to, array $context = []): self
    {
        return new self(self::INVALID_TRANSITION, 422, "Cannot transition from '{$from}' to '{$to}'.", $context);
    }
}
