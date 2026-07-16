<?php

declare(strict_types=1);

namespace App\Modules\Menu\Exceptions;

use RuntimeException;

final class MenuException extends RuntimeException
{
    public const INVALID_INPUT = 'MENU_INVALID_INPUT';

    public const SCOPE_VIOLATION = 'MENU_SCOPE_VIOLATION';

    public const NOT_FOUND = 'MENU_NOT_FOUND';

    public const INVALID_STATE = 'MENU_INVALID_STATE';

    public const STALE_VERSION = 'MENU_STALE_VERSION';

    public const IN_USE = 'MENU_ENTITY_IN_USE';

    public const INSUFFICIENT_STOCK = 'MENU_INSUFFICIENT_STOCK';

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
}
