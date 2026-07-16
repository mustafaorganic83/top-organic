<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Resources;

use App\Models\DiningTable;
use App\Models\Floor;
use App\Models\Reservation;
use App\Models\ReservationWaitlistEntry;
use App\Models\Room;

/**
 * Stateless formatters that turn Tables aggregates into stable API payloads.
 * Kept free of business logic so controllers stay thin and responses stay
 * consistent across list, show, and mutation endpoints.
 */
final class ReservationResource
{
    /** @return array<string, mixed> */
    public static function floor(Floor $floor): array
    {
        return [
            'id' => $floor->id, 'code' => $floor->code, 'name' => $floor->name,
            'status' => $floor->status, 'layout_revision' => $floor->layout_revision,
            'layout' => $floor->layout, 'lock_version' => $floor->lock_version,
            'rooms' => $floor->relationLoaded('rooms')
                ? $floor->rooms->map(fn (Room $r) => self::room($r))->values() : null,
            'tables' => $floor->relationLoaded('tables')
                ? $floor->tables->map(fn (DiningTable $t) => self::table($t))->values() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function room(Room $room): array
    {
        return [
            'id' => $room->id, 'floor_id' => $room->floor_id, 'code' => $room->code, 'name' => $room->name,
            'kind' => $room->kind, 'capacity' => $room->capacity,
            'minimum_spend_amount' => $room->minimum_spend_amount, 'currency' => $room->currency,
            'requires_approval' => $room->requires_approval, 'description' => $room->description,
            'status' => $room->status, 'lock_version' => $room->lock_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function table(DiningTable $table): array
    {
        return [
            'id' => $table->id, 'floor_id' => $table->floor_id, 'room_id' => $table->room_id,
            'code' => $table->code, 'name' => $table->name, 'area' => $table->area, 'shape' => $table->shape,
            'capacity' => $table->capacity, 'is_reservable' => $table->is_reservable,
            'sort_order' => $table->sort_order, 'status' => $table->status,
            'occupancy_status' => $table->occupancy_status, 'lock_version' => $table->lock_version,
            'floor' => $table->relationLoaded('floor') && $table->floor !== null
                ? ['id' => $table->floor->id, 'code' => $table->floor->code, 'name' => $table->floor->name] : null,
            'room' => $table->relationLoaded('room') && $table->room !== null
                ? ['id' => $table->room->id, 'code' => $table->room->code, 'kind' => $table->room->kind] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function reservation(Reservation $r): array
    {
        return [
            'id' => $r->id, 'reference' => $r->reference, 'channel' => $r->channel, 'state' => $r->state,
            'guest_name' => $r->guest_name, 'guest_phone' => $r->guest_phone, 'guest_email' => $r->guest_email,
            'party_size' => $r->party_size, 'area' => $r->area, 'is_walk_in' => $r->is_walk_in,
            'reserved_for' => $r->reserved_for?->toISOString(), 'duration_minutes' => $r->duration_minutes,
            'customer_id' => $r->customer_id, 'room_id' => $r->room_id, 'dining_table_id' => $r->dining_table_id,
            'table_session_id' => $r->table_session_id, 'reservation_source_id' => $r->reservation_source_id,
            'special_requests' => $r->special_requests, 'confirmation_channel' => $r->confirmation_channel,
            'confirmed_at' => $r->confirmed_at?->toISOString(), 'seated_at' => $r->seated_at?->toISOString(),
            'completed_at' => $r->completed_at?->toISOString(), 'cancelled_at' => $r->cancelled_at?->toISOString(),
            'cancellation_reason' => $r->cancellation_reason, 'lock_version' => $r->lock_version,
            'table' => $r->relationLoaded('table') && $r->table !== null ? self::table($r->table) : null,
            'audit_logs' => $r->relationLoaded('auditLogs')
                ? $r->auditLogs->map(fn ($log) => ['action' => $log->action, 'from_state' => $log->from_state,
                    'to_state' => $log->to_state, 'metadata' => $log->metadata,
                    'occurred_at' => $log->occurred_at?->toISOString()])->values() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function waitlist(ReservationWaitlistEntry $e): array
    {
        return [
            'id' => $e->id, 'customer_id' => $e->customer_id, 'guest_name' => $e->guest_name,
            'guest_phone' => $e->guest_phone, 'party_size' => $e->party_size, 'area' => $e->area,
            'position' => $e->position, 'quoted_wait_minutes' => $e->quoted_wait_minutes, 'state' => $e->state,
            'notes' => $e->notes, 'joined_at' => $e->joined_at?->toISOString(),
            'notified_at' => $e->notified_at?->toISOString(), 'seated_at' => $e->seated_at?->toISOString(),
            'lock_version' => $e->lock_version,
        ];
    }
}
