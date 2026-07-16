<?php

declare(strict_types=1);

namespace App\Modules\Sales\Data;

use App\Modules\Sales\Exceptions\SalesException;

/**
 * A single client-supplied offline operation inside a push batch. Immutable
 * value object; tenant/branch/device are never taken from the payload but from
 * the trusted SalesContext, so only the intent fields live here.
 */
final readonly class SyncOperation
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $clientOperationId,
        public string $entityType,
        public string $entityId,
        public string $command,
        public int $deviceSequence,
        public int $logicalClock,
        public array $payload,
        public string $fingerprint,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $clientOperationId = (string) ($raw['client_operation_id'] ?? '');
        $entityType = (string) ($raw['entity_type'] ?? '');
        $entityId = (string) ($raw['entity_id'] ?? '');
        $command = (string) ($raw['command'] ?? '');
        $payload = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];
        if ($clientOperationId === '' || $entityType === '' || $entityId === '' || $command === '') {
            throw SalesException::invalid('Each operation requires a client operation ID, entity type, entity ID, and command.');
        }
        if (! array_key_exists('device_sequence', $raw)) {
            throw SalesException::invalid('Each operation requires a device sequence.');
        }

        return new self(
            $clientOperationId,
            $entityType,
            $entityId,
            $command,
            (int) $raw['device_sequence'],
            (int) ($raw['logical_clock'] ?? 0),
            $payload,
            self::fingerprint($entityType, $entityId, $command, (int) $raw['device_sequence'],
                (int) ($raw['logical_clock'] ?? 0), $payload),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function fingerprint(
        string $entityType,
        string $entityId,
        string $command,
        int $deviceSequence,
        int $logicalClock,
        array $payload,
    ): string {
        return hash('sha256', implode('|', [
            $entityType, $entityId, $command, $deviceSequence, $logicalClock, self::canonical($payload),
        ]));
    }

    /** @param array<string, mixed> $payload */
    private static function canonical(array $payload): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => is_array($item) ? $normalize($item) : $item, $value);
        };

        return (string) json_encode($normalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
