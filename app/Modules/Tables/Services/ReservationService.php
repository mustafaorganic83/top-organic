<?php

declare(strict_types=1);

namespace App\Modules\Tables\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Models\ReservationSource;
use App\Models\TableSession;
use App\Modules\Tables\Data\ReservationContext;
use App\Modules\Tables\Exceptions\ReservationException;
use App\Modules\Tables\Services\Concerns\WritesReservationAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the reservation lifecycle across every channel (walk-in, phone, call
 * centre, online, WhatsApp, AI): create → confirm → assign table → seat →
 * complete, plus cancel and no-show. Table assignment reserves the physical
 * table's live occupancy; seating opens a POS-compatible table session so the
 * order flow continues seamlessly. All scope is trusted, never from payload.
 */
final class ReservationService
{
    use WritesReservationAudit;

    private const ACTIVE_STATES = ['pending', 'confirmed', 'seated'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ReservationContext $context, array $data): Reservation
    {
        return DB::transaction(function () use ($context, $data): Reservation {
            $source = $this->resolveSource($context, $data['reservation_source_id'] ?? null);
            $customer = $this->resolveCustomer($context, $data['customer_id'] ?? null);
            $isWalkIn = (bool) ($data['is_walk_in'] ?? false);
            $reservedFor = isset($data['reserved_for'])
                ? Carbon::parse((string) $data['reserved_for']) : now();
            $autoConfirm = $isWalkIn || (bool) ($source?->auto_confirm ?? false);

            $reservation = Reservation::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'reservation_source_id' => $source?->id, 'customer_id' => $customer?->id,
                'room_id' => $data['room_id'] ?? null, 'reference' => $this->nextReference($context),
                'channel' => $data['channel'] ?? $source?->channel ?? ($isWalkIn ? 'walk_in' : 'phone'),
                'guest_name' => $data['guest_name'] ?? $customer?->name ?? 'Guest',
                'guest_phone' => $data['guest_phone'] ?? $customer?->phone,
                'guest_email' => $data['guest_email'] ?? null,
                'party_size' => (int) $data['party_size'], 'area' => $data['area'] ?? null,
                'reserved_for' => $reservedFor, 'duration_minutes' => $data['duration_minutes'] ?? 90,
                'state' => $autoConfirm ? 'confirmed' : 'pending', 'is_walk_in' => $isWalkIn,
                'special_requests' => $data['special_requests'] ?? null,
                'customer_snapshot' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name],
                'confirmation_channel' => $autoConfirm ? ($data['confirmation_channel'] ?? 'system') : null,
                'confirmed_at' => $autoConfirm ? now() : null,
                'created_by' => $context->userId, 'client_operation_id' => $data['client_operation_id'] ?? null,
                'lock_version' => 0,
            ]);
            $this->audit($context, 'reservation', $reservation->id, 'reservation.created',
                null, $reservation->state, ['channel' => $reservation->channel], $reservation->id);

            return $reservation->refresh();
        }, 3);
    }

    public function confirm(ReservationContext $context, string $id, int $expectedVersion, ?string $channel): Reservation
    {
        return $this->transition($context, $id, $expectedVersion, 'confirmed', function (Reservation $r) use ($channel): void {
            if ($r->state !== 'pending') {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a pending reservation can be confirmed.');
            }
            $r->confirmation_channel = $channel ?? 'system';
            $r->confirmed_at = now();
        });
    }

    public function assignTable(ReservationContext $context, string $id, string $tableId, int $expectedVersion): Reservation
    {
        return DB::transaction(function () use ($context, $id, $tableId, $expectedVersion): Reservation {
            $reservation = $this->forUpdate($context, $id);
            $this->assertVersion($reservation->lock_version, $expectedVersion);
            if (! in_array($reservation->state, ['pending', 'confirmed'], true)) {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a pending or confirmed reservation can be assigned a table.');
            }
            $table = $this->tableForUpdate($context, $tableId);
            if (! $table->is_reservable || $table->status !== 'active') {
                throw ReservationException::conflict(ReservationException::TABLE_UNAVAILABLE,
                    'The table is not available for reservations.');
            }
            if (! in_array($table->occupancy_status, ['available', 'reserved'], true)) {
                throw ReservationException::conflict(ReservationException::TABLE_UNAVAILABLE,
                    'The table is currently occupied or blocked.');
            }
            if ($reservation->party_size > $table->capacity) {
                throw ReservationException::conflict(ReservationException::CAPACITY_EXCEEDED,
                    'The party size exceeds the table capacity.',
                    ['capacity' => $table->capacity, 'party_size' => $reservation->party_size]);
            }
            $reservation->dining_table_id = $table->id;
            $reservation->lock_version = $reservation->lock_version + 1;
            $reservation->save();
            $table->occupancy_status = 'reserved';
            $table->lock_version = $table->lock_version + 1;
            $table->save();
            $this->audit($context, 'reservation', $reservation->id, 'reservation.table_assigned',
                null, $reservation->state, ['dining_table_id' => $table->id], $reservation->id);

            return $reservation->refresh();
        }, 3);
    }

    public function seat(ReservationContext $context, string $id, int $expectedVersion): Reservation
    {
        return DB::transaction(function () use ($context, $id, $expectedVersion): Reservation {
            $reservation = $this->forUpdate($context, $id);
            $this->assertVersion($reservation->lock_version, $expectedVersion);
            if (! in_array($reservation->state, ['pending', 'confirmed'], true)) {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a pending or confirmed reservation can be seated.');
            }
            if ($reservation->dining_table_id === null) {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Assign a table before seating the reservation.');
            }
            $table = $this->tableForUpdate($context, $reservation->dining_table_id);
            $openSession = TableSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('dining_table_id', $table->id)
                ->where('state', 'open')->exists();
            if ($openSession) {
                throw ReservationException::conflict(ReservationException::TABLE_UNAVAILABLE,
                    'The dining table already has an open session.');
            }
            $session = TableSession::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'dining_table_id' => $table->id, 'opened_by' => $context->userId,
                'guest_count' => $reservation->party_size, 'state' => 'open',
                'opened_at' => now(), 'lock_version' => 0,
            ]);
            $reservation->table_session_id = $session->id;
            $reservation->state = 'seated';
            $reservation->seated_at = now();
            $reservation->seated_by = $context->userId;
            $reservation->lock_version = $reservation->lock_version + 1;
            $reservation->save();
            $table->occupancy_status = 'occupied';
            $table->lock_version = $table->lock_version + 1;
            $table->save();
            $this->audit($context, 'reservation', $reservation->id, 'reservation.seated',
                'confirmed', 'seated', ['table_session_id' => $session->id], $reservation->id);

            return $reservation->refresh();
        }, 3);
    }

    public function complete(ReservationContext $context, string $id, int $expectedVersion): Reservation
    {
        return DB::transaction(function () use ($context, $id, $expectedVersion): Reservation {
            $reservation = $this->forUpdate($context, $id);
            $this->assertVersion($reservation->lock_version, $expectedVersion);
            if ($reservation->state !== 'seated') {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a seated reservation can be completed.');
            }
            $reservation->state = 'completed';
            $reservation->completed_at = now();
            $reservation->lock_version = $reservation->lock_version + 1;
            $reservation->save();
            $this->releaseTable($context, $reservation->dining_table_id);
            $this->audit($context, 'reservation', $reservation->id, 'reservation.completed',
                'seated', 'completed', [], $reservation->id);

            return $reservation->refresh();
        }, 3);
    }

    public function cancel(ReservationContext $context, string $id, int $expectedVersion, ?string $reason): Reservation
    {
        return DB::transaction(function () use ($context, $id, $expectedVersion, $reason): Reservation {
            $reservation = $this->forUpdate($context, $id);
            $this->assertVersion($reservation->lock_version, $expectedVersion);
            if (! in_array($reservation->state, ['pending', 'confirmed'], true)) {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a pending or confirmed reservation can be cancelled.');
            }
            $from = $reservation->state;
            $reservation->state = 'cancelled';
            $reservation->cancelled_at = now();
            $reservation->cancellation_reason = $reason;
            $reservation->lock_version = $reservation->lock_version + 1;
            $reservation->save();
            $this->releaseTable($context, $reservation->dining_table_id);
            $this->audit($context, 'reservation', $reservation->id, 'reservation.cancelled',
                $from, 'cancelled', ['reason' => $reason], $reservation->id);

            return $reservation->refresh();
        }, 3);
    }

    public function markNoShow(ReservationContext $context, string $id, int $expectedVersion): Reservation
    {
        return DB::transaction(function () use ($context, $id, $expectedVersion): Reservation {
            $reservation = $this->forUpdate($context, $id);
            $this->assertVersion($reservation->lock_version, $expectedVersion);
            if (! in_array($reservation->state, ['pending', 'confirmed'], true)) {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a pending or confirmed reservation can be marked as a no-show.');
            }
            $from = $reservation->state;
            $reservation->state = 'no_show';
            $reservation->lock_version = $reservation->lock_version + 1;
            $reservation->save();
            $this->releaseTable($context, $reservation->dining_table_id);
            $this->audit($context, 'reservation', $reservation->id, 'reservation.no_show',
                $from, 'no_show', [], $reservation->id);

            return $reservation->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Reservation>
     */
    public function list(ReservationContext $context, array $filters, int $perPage): LengthAwarePaginator
    {
        return Reservation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->when(isset($filters['state']), fn ($q) => $q->where('state', $filters['state']))
            ->when(isset($filters['channel']), fn ($q) => $q->where('channel', $filters['channel']))
            ->when(isset($filters['date']), fn ($q) => $q->whereDate('reserved_for', $filters['date']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->with(['table' => fn ($q) => $q->withoutGlobalScopes(),
                'room' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('reserved_for')->paginate($perPage);
    }

    public function find(ReservationContext $context, string $id): Reservation
    {
        return Reservation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->with(['table' => fn ($q) => $q->withoutGlobalScopes(),
                'room' => fn ($q) => $q->withoutGlobalScopes(),
                'auditLogs' => fn ($q) => $q->withoutGlobalScopes()->orderBy('occurred_at')])
            ->whereKey($id)->first()
            ?? throw ReservationException::notFound('The reservation was not found in this branch.');
    }

    /**
     * Cross-branch reservation history for a customer within the tenant.
     *
     * @return LengthAwarePaginator<int, Reservation>
     */
    public function customerHistory(ReservationContext $context, string $customerId, int $perPage): LengthAwarePaginator
    {
        $this->resolveCustomer($context, $customerId)
            ?? throw ReservationException::notFound('The customer was not found in this tenant.');

        return Reservation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('customer_id', $customerId)->latest('reserved_for')->paginate($perPage);
    }

    private function transition(ReservationContext $context, string $id, int $expectedVersion, string $to, callable $mutate): Reservation
    {
        return DB::transaction(function () use ($context, $id, $expectedVersion, $to, $mutate): Reservation {
            $reservation = $this->forUpdate($context, $id);
            $this->assertVersion($reservation->lock_version, $expectedVersion);
            $from = $reservation->state;
            $mutate($reservation);
            $reservation->state = $to;
            $reservation->lock_version = $reservation->lock_version + 1;
            $reservation->save();
            $this->audit($context, 'reservation', $reservation->id, 'reservation.'.$to, $from, $to, [], $reservation->id);

            return $reservation->refresh();
        }, 3);
    }

    private function releaseTable(ReservationContext $context, ?string $tableId): void
    {
        if ($tableId === null) {
            return;
        }
        $table = DiningTable::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($tableId)->lockForUpdate()->first();
        if ($table !== null && in_array($table->occupancy_status, ['reserved', 'occupied'], true)) {
            $table->occupancy_status = 'available';
            $table->lock_version = $table->lock_version + 1;
            $table->save();
        }
    }

    private function forUpdate(ReservationContext $context, string $id): Reservation
    {
        return Reservation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
            ?? throw ReservationException::notFound('The reservation was not found in this branch.');
    }

    private function tableForUpdate(ReservationContext $context, string $id): DiningTable
    {
        return DiningTable::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
            ?? throw ReservationException::notFound('The dining table was not found in this branch.');
    }

    private function resolveSource(ReservationContext $context, ?string $sourceId): ?ReservationSource
    {
        if ($sourceId === null) {
            return null;
        }

        return ReservationSource::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where(fn ($q) => $q->where('branch_id', $context->branchId)->orWhereNull('branch_id'))
            ->where('is_active', true)->whereKey($sourceId)->first()
            ?? throw ReservationException::notFound('The reservation source was not found.');
    }

    private function resolveCustomer(ReservationContext $context, ?string $customerId): ?Customer
    {
        if ($customerId === null) {
            return null;
        }

        return Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($customerId)->first();
    }

    private function nextReference(ReservationContext $context): string
    {
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($context->branchId)->first();
        $code = strtoupper($branch?->code ?? 'BR');
        $date = now()->format('Ymd');
        $count = Reservation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereDate('created_at', now()->toDateString())->count();

        return sprintf('RSV-%s-%s-%04d', $code, $date, $count + 1);
    }
}
