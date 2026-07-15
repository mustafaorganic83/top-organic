<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\DomainOutboxEvent;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\SyncChangeLogEntry;
use App\Modules\Sales\Data\SalesContext;

final class OrderJournal
{
    public function __construct(private readonly SequenceNumberService $numbers) {}

    /** @param array<string, mixed> $payload */
    public function record(
        SalesContext $context,
        Order $order,
        string $eventType,
        string $clientOperationId,
        array $payload = [],
    ): OrderEvent {
        $eventOperation = $this->normalize($clientOperationId);
        $sequence = (int) OrderEvent::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)->max('sequence') + 1;
        $occurredAt = now();
        $event = OrderEvent::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
            'sequence' => $sequence, 'event_type' => $eventType, 'event_version' => 1, 'payload' => $payload,
            'actor_id' => $context->userId, 'device_id' => $context->deviceId,
            'client_operation_id' => $eventOperation, 'logical_clock' => (int) $order->lock_version,
            'occurred_at' => $occurredAt,
        ]);
        DomainOutboxEvent::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
            'aggregate_type' => 'order', 'aggregate_id' => $order->id, 'aggregate_sequence' => $sequence,
            'event_type' => $eventType, 'event_version' => 1, 'payload' => $payload,
            'correlation_id' => $eventOperation,
            'idempotency_key' => $this->key($clientOperationId, 'outbox'), 'available_at' => $occurredAt,
        ]);
        SyncChangeLogEntry::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
            'change_sequence' => $this->numbers->nextSequence($context, 'change_log', '1970-01-01'),
            'entity_type' => 'order', 'entity_id' => $order->id, 'entity_revision' => (int) $order->lock_version,
            'operation' => 'upsert', 'manifest' => ['event' => $eventType], 'occurred_at' => $occurredAt,
        ]);

        return $event;
    }

    private function key(string $operation, string $suffix): string
    {
        $key = $operation.':'.$suffix;

        return strlen($key) <= 128 ? $key : hash('sha256', $key);
    }

    private function normalize(string $operation): string
    {
        return strlen($operation) <= 128 ? $operation : hash('sha256', $operation);
    }
}
