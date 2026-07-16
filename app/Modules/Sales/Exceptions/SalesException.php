<?php

declare(strict_types=1);

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

final class SalesException extends RuntimeException
{
    public const INVALID_INPUT = 'SALES_INVALID_INPUT';

    public const INVALID_MONEY = 'SALES_INVALID_MONEY';

    public const INVALID_QUANTITY = 'SALES_INVALID_QUANTITY';

    public const CURRENCY_MISMATCH = 'SALES_CURRENCY_MISMATCH';

    public const ARITHMETIC_OVERFLOW = 'SALES_ARITHMETIC_OVERFLOW';

    public const SCOPE_VIOLATION = 'SALES_SCOPE_VIOLATION';

    public const NOT_FOUND = 'SALES_NOT_FOUND';

    public const CATALOG_UNAVAILABLE = 'SALES_CATALOG_UNAVAILABLE';

    public const INVALID_STATE = 'SALES_INVALID_STATE';

    public const STALE_VERSION = 'SALES_STALE_VERSION';

    public const TERMINAL_ORDER = 'SALES_TERMINAL_ORDER';

    public const IDEMPOTENCY_CONFLICT = 'SALES_IDEMPOTENCY_CONFLICT';

    public const RESYNC_REQUIRED = 'SALES_RESYNC_REQUIRED';

    public const LIMIT_EXCEEDED = 'SALES_LIMIT_EXCEEDED';

    public const INSUFFICIENT_BALANCE = 'SALES_INSUFFICIENT_BALANCE';

    public const PAYMENT_EXCEEDS_DUE = 'SALES_PAYMENT_EXCEEDS_DUE';

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
}
