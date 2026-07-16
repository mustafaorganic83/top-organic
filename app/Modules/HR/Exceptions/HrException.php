<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use RuntimeException;

final class HrException extends RuntimeException
{
    public const INVALID_INPUT = 'HR_INVALID_INPUT';

    public const SCOPE_VIOLATION = 'HR_SCOPE_VIOLATION';

    public const NOT_FOUND = 'HR_NOT_FOUND';

    public const INVALID_STATE = 'HR_INVALID_STATE';

    public const STALE_VERSION = 'HR_STALE_VERSION';

    public const IN_USE = 'HR_ENTITY_IN_USE';

    public const ALREADY_APPROVED = 'HR_ALREADY_APPROVED';

    public const INVALID_TRANSITION = 'HR_INVALID_TRANSITION';

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
