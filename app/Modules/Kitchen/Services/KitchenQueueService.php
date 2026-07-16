<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Services;

use App\Models\KdsTicket;
use App\Modules\Kitchen\Data\KitchenContext;
use App\Modules\Kitchen\Exceptions\KitchenException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read side of the kitchen board. Groups live tickets into the four phase
 * queues the kitchen screens render — preparation (queued), cooking
 * (in_progress), ready (ready) and served (served) — and computes the kitchen
 * timer for each: elapsed seconds since dispatch, the applicable prep SLA, and
 * whether the ticket is overdue. Priority tickets always sort first.
 */
final class KitchenQueueService
{
    /** Requested queue name => underlying ticket state. */
    public const PHASES = [
        'preparation' => 'queued',
        'cooking' => 'in_progress',
        'ready' => 'ready',
        'served' => 'served',
    ];

    /**
     * The full board for a station (or the whole branch), keyed by phase.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function board(KitchenContext $context, ?string $stationId, int $servedLimit = 25): array
    {
        $board = [];
        foreach (self::PHASES as $phase => $state) {
            $board[$phase] = $this->tickets($context, $stationId, $state, $phase === 'served' ? $servedLimit : null)
                ->map(fn (KdsTicket $ticket) => $this->present($ticket))
                ->values()->all();
        }

        return $board;
    }

    /**
     * A single phase queue.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function queue(KitchenContext $context, string $phase, ?string $stationId): Collection
    {
        $state = self::PHASES[$phase]
            ?? throw KitchenException::invalid('Unknown kitchen queue phase.', ['phase' => $phase]);
        $limit = $phase === 'served' ? 50 : null;

        return $this->tickets($context, $stationId, $state, $limit)
            ->map(fn (KdsTicket $ticket) => $this->present($ticket))
            ->values();
    }

    public function find(KitchenContext $context, string $id): KdsTicket
    {
        return KdsTicket::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)->with(['station', 'chef', 'items', 'events'])->first()
            ?? throw KitchenException::notFound('The kitchen ticket was not found.');
    }

    /** @return Collection<int, KdsTicket> */
    private function tickets(KitchenContext $context, ?string $stationId, string $state, ?int $limit): Collection
    {
        $query = KdsTicket::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where('state', $state)
            ->when($stationId !== null, fn ($q) => $q->where('kds_station_id', $stationId))
            ->with(['station', 'chef', 'items'])
            ->orderByDesc('is_priority')
            ->orderBy('priority')
            ->when($state === 'served', fn ($q) => $q->orderByDesc('served_at'), fn ($q) => $q->oldest());
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Attaches the live kitchen timer to a ticket for the board payload.
     *
     * @return array<string, mixed>
     */
    public function present(KdsTicket $ticket): array
    {
        $now = Carbon::now();
        $anchor = $ticket->started_at ?? $ticket->created_at;
        $sla = $ticket->sla_seconds ?? $ticket->station?->sla_seconds ?? $ticket->station?->default_prep_seconds;
        $elapsed = $anchor !== null && $ticket->served_at === null ? max(0, $now->diffInSeconds($anchor, true)) : null;
        $prep = $ticket->prep_seconds;
        if ($prep === null && $ticket->started_at !== null && $ticket->ready_at !== null) {
            $prep = max(0, $ticket->ready_at->diffInSeconds($ticket->started_at, true));
        }

        return [
            'id' => $ticket->id,
            'order_id' => $ticket->order_id,
            'number' => $ticket->number,
            'state' => $ticket->state,
            'phase' => array_search($ticket->state, self::PHASES, true) ?: $ticket->state,
            'priority' => $ticket->priority,
            'is_priority' => (bool) $ticket->is_priority,
            'chef_id' => $ticket->chef_id,
            'chef_name' => $ticket->chef?->name,
            'station' => $ticket->station === null ? null : [
                'id' => $ticket->station->id, 'code' => $ticket->station->code,
                'name' => $ticket->station->name, 'station_type' => $ticket->station->station_type,
            ],
            'timer' => [
                'sla_seconds' => $sla,
                'elapsed_seconds' => $elapsed,
                'prep_seconds' => $prep,
                'is_overdue' => $sla !== null && $elapsed !== null && $elapsed > $sla,
            ],
            'started_at' => $ticket->started_at?->toISOString(),
            'ready_at' => $ticket->ready_at?->toISOString(),
            'served_at' => $ticket->served_at?->toISOString(),
            'assigned_at' => $ticket->assigned_at?->toISOString(),
            'lock_version' => $ticket->lock_version,
            'items' => $ticket->relationLoaded('items')
                ? $ticket->items->map(fn ($item) => [
                    'id' => $item->id, 'order_item_id' => $item->order_item_id, 'quantity' => $item->quantity,
                    'preparation' => $item->preparation_snapshot, 'state' => $item->state,
                    'prep_seconds' => $item->prep_seconds,
                ])->values()->all()
                : [],
        ];
    }
}
