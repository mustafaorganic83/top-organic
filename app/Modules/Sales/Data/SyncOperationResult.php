<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

/**
 * Per-operation outcome returned by a push batch. Results are one of
 * applied | duplicate | conflict | rejected; the body carries only
 * non-sensitive entity identifiers and revisions for the edge to reconcile.
 */
final readonly class SyncOperationResult
{
    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const CONFLICT = 'conflict';

    public const REJECTED = 'rejected';

    /** @param array<string, mixed> $body */
    public function __construct(
        public string $clientOperationId,
        public string $result,
        public string $resultCode,
        public array $body = [],
        public ?int $entityRevision = null,
    ) {}

    /** @param array<string, mixed> $body */
    public static function applied(string $operationId, string $code, array $body, ?int $revision): self
    {
        return new self($operationId, self::APPLIED, $code, $body, $revision);
    }

    /** @param array<string, mixed> $body */
    public static function duplicate(string $operationId, string $code, array $body, ?int $revision): self
    {
        return new self($operationId, self::DUPLICATE, $code, $body, $revision);
    }

    public static function conflict(string $operationId, string $code, ?int $revision = null): self
    {
        return new self($operationId, self::CONFLICT, $code, [], $revision);
    }

    public static function rejected(string $operationId, string $code): self
    {
        return new self($operationId, self::REJECTED, $code);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_operation_id' => $this->clientOperationId,
            'result' => $this->result,
            'result_code' => $this->resultCode,
            'entity_revision' => $this->entityRevision,
            'body' => $this->body,
        ];
    }
}
