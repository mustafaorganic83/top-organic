<?php

declare(strict_types=1);

namespace App\Modules\Tables\Services\Concerns;

use App\Models\ReservationAuditLog;
use App\Modules\Tables\Data\ReservationContext;
use App\Modules\Tables\Exceptions\ReservationException;

/**
 * Shared helpers for the Tables services: optimistic-lock version assertion
 * and an append-only audit trail entry writer scoped to the trusted context.
 */
trait WritesReservationAudit
{
    protected function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ReservationException::conflict(
                ReservationException::STALE_VERSION,
                'The record was changed by another operation.',
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    protected function audit(
        ReservationContext $context,
        string $entityType,
        string $entityId,
        string $action,
        ?string $fromState = null,
        ?string $toState = null,
        array $metadata = [],
        ?string $reservationId = null,
    ): void {
        ReservationAuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'branch_id' => $context->branchId,
            'reservation_id' => $reservationId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'from_state' => $fromState,
            'to_state' => $toState,
            'metadata' => $metadata === [] ? null : $metadata,
            'actor_id' => $context->userId,
            'device_id' => $context->deviceId,
            'occurred_at' => now(),
        ]);
    }
}
