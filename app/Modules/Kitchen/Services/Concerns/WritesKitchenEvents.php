<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Services\Concerns;

use App\Models\KdsTicket;
use App\Models\KdsTicketEvent;
use App\Modules\Kitchen\Data\KitchenContext;
use App\Modules\Kitchen\Exceptions\KitchenException;

/**
 * Shared helpers for the Kitchen services: optimistic-lock version assertion,
 * append-only event writing on the existing kds_ticket_events log (so the
 * Sales KDS and the Kitchen module share one immutable trail), and a
 * client-operation replay guard for offline-safe idempotency.
 */
trait WritesKitchenEvents
{
    protected function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw KitchenException::conflict(
                KitchenException::STALE_VERSION,
                'The kitchen ticket was changed by another operation.',
            );
        }
    }

    protected function replay(KitchenContext $context, string $operation): ?KdsTicket
    {
        $event = KdsTicketEvent::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where('client_operation_id', $operation)
            ->first();

        return $event === null
            ? null
            : KdsTicket::withoutGlobalScopes()->findOrFail($event->kds_ticket_id)
                ->load(['station', 'chef', 'items', 'events']);
    }

    /** @param array<string, mixed> $payload */
    protected function event(
        KitchenContext $context,
        KdsTicket $ticket,
        string $type,
        string $operation,
        ?string $reason = null,
        array $payload = [],
    ): void {
        $sequence = $ticket->last_sequence + 1;
        KdsTicketEvent::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'branch_id' => $context->branchId,
            'kds_ticket_id' => $ticket->id,
            'sequence' => $sequence,
            'event_type' => $type,
            'actor_id' => $context->userId,
            'device_id' => $context->deviceId,
            'reason' => $reason,
            'payload' => $payload === [] ? null : $payload,
            'client_operation_id' => $operation,
            'occurred_at' => now(),
        ]);
        $ticket->last_sequence = $sequence;
        $ticket->save();
    }
}
