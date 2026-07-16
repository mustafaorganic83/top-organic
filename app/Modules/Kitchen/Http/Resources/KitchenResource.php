<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Resources;

use App\Models\KdsStation;
use App\Models\KdsTicket;
use App\Modules\Kitchen\Services\KitchenQueueService;

/**
 * Stateless formatters that turn Kitchen aggregates into stable API payloads.
 * Ticket formatting delegates to the queue presenter so the live timer
 * (elapsed / SLA / overdue) is identical whether a ticket is returned from a
 * queue read or a mutation.
 */
final class KitchenResource
{
    /** @return array<string, mixed> */
    public static function station(KdsStation $station): array
    {
        return [
            'id' => $station->id,
            'code' => $station->code,
            'name' => $station->name,
            'station_type' => $station->station_type,
            'device_id' => $station->device_id,
            'sla_seconds' => $station->sla_seconds,
            'default_prep_seconds' => $station->default_prep_seconds,
            'sort_order' => $station->sort_order,
            'screen_config' => $station->screen_config,
            'status' => $station->status,
            'lock_version' => $station->lock_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function ticket(KdsTicket $ticket): array
    {
        $payload = app(KitchenQueueService::class)->present(
            $ticket->loadMissing(['station', 'chef', 'items'])
        );
        $payload['events'] = $ticket->relationLoaded('events')
            ? $ticket->events->map(fn ($event) => [
                'id' => $event->id, 'sequence' => $event->sequence, 'type' => $event->event_type,
                'reason' => $event->reason, 'occurred_at' => $event->occurred_at?->toISOString(),
            ])->values()->all()
            : null;

        return $payload;
    }
}
