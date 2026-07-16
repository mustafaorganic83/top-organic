<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

final class AccountingException extends RuntimeException
{
    public const NOT_FOUND = 'ACCOUNTING_NOT_FOUND';
    public const UNBALANCED = 'JOURNAL_UNBALANCED';
    public const ALREADY_POSTED = 'JOURNAL_ALREADY_POSTED';
    public const PERIOD_CLOSED = 'PERIOD_CLOSED';
    public const IN_USE = 'ACCOUNTING_IN_USE';
    public const STALE_VERSION = 'STALE_VERSION';
    public const INVALID_STATE = 'INVALID_STATE';
    public const INVALID = 'ACCOUNTING_INVALID';

    /** @param array<string, mixed>|null $context */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly ?array $context = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(self::NOT_FOUND, $message, 404);
    }

    public static function unbalanced(): self
    {
        return new self(self::UNBALANCED, 'Journal entry debits do not equal credits.', 422);
    }

    public static function alreadyPosted(): self
    {
        return new self(self::ALREADY_POSTED, 'The journal entry is already posted.', 409);
    }

    public static function periodClosed(string $period): self
    {
        return new self(self::PERIOD_CLOSED, "Accounting period {$period} is closed.", 422);
    }

    public static function inUse(string $message = 'Resource is in use.'): self
    {
        return new self(self::IN_USE, $message, 409);
    }

    /** @param array<string, mixed>|null $context */
    public static function conflict(string $code, string $message, ?array $context = null): self
    {
        return new self($code, $message, 409, $context);
    }

    public static function invalidState(string $message): self
    {
        return new self(self::INVALID_STATE, $message, 422);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message, 422);
    }
}
