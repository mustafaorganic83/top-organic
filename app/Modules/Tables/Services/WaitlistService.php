<?php

declare(strict_types=1);

namespace App\Modules\Tables\Services;

use App\Models\Customer;
use App\Models\ReservationWaitlistEntry;
use App\Modules\Tables\Data\ReservationContext;
use App\Modules\Tables\Exceptions\ReservationException;
use App\Modules\Tables\Services\Concerns\WritesReservationAudit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages the branch waiting list for walk-in guests without an immediate
 * table: FIFO positioning, notification, seating, and cancellation. Position
 * is assigned atomically per branch so concurrent hosts never collide.
 */
final class WaitlistService
{
    use WritesReservationAudit;

    /** @return Collection<int, ReservationWaitlistEntry> */
    public function active(ReservationContext $context): Collection
    {
        return ReservationWaitlistEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereIn('state', ['waiting', 'notified'])
            ->orderBy('position')->get();
    }

    /** @param array<string, mixed> $data */
    public function join(ReservationContext $context, array $data): ReservationWaitlistEntry
    {
        return DB::transaction(function () use ($context, $data): ReservationWaitlistEntry {
            $customer = null;
            if (isset($data['customer_id'])) {
                $customer = Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                    ->whereKey($data['customer_id'])->first()
                    ?? throw ReservationException::notFound('The customer was not found in this tenant.');
            }
            $maxPosition = (int) ReservationWaitlistEntry::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)->where('branch_id', $context->branchId)
                ->whereIn('state', ['waiting', 'notified'])->lockForUpdate()->max('position');

            $entry = ReservationWaitlistEntry::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'customer_id' => $customer?->id,
                'guest_name' => $data['guest_name'] ?? $customer?->name ?? 'Guest',
                'guest_phone' => $data['guest_phone'] ?? $customer?->phone,
                'party_size' => (int) $data['party_size'], 'area' => $data['area'] ?? null,
                'position' => $maxPosition + 1, 'quoted_wait_minutes' => $data['quoted_wait_minutes'] ?? null,
                'state' => 'waiting', 'notes' => $data['notes'] ?? null, 'joined_at' => now(),
                'created_by' => $context->userId, 'lock_version' => 0,
            ]);
            $this->audit($context, 'waitlist_entry', $entry->id, 'waitlist.joined', null, 'waiting',
                ['position' => $entry->position]);

            return $entry;
        }, 3);
    }

    public function notify(ReservationContext $context, string $id, int $expectedVersion): ReservationWaitlistEntry
    {
        return $this->update($context, $id, $expectedVersion, function (ReservationWaitlistEntry $e): void {
            if ($e->state !== 'waiting') {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a waiting entry can be notified.');
            }
            $e->state = 'notified';
            $e->notified_at = now();
        }, 'waitlist.notified');
    }

    public function seat(ReservationContext $context, string $id, int $expectedVersion): ReservationWaitlistEntry
    {
        return $this->update($context, $id, $expectedVersion, function (ReservationWaitlistEntry $e): void {
            if (! in_array($e->state, ['waiting', 'notified'], true)) {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a waiting or notified entry can be seated.');
            }
            $e->state = 'seated';
            $e->seated_at = now();
        }, 'waitlist.seated');
    }

    public function cancel(ReservationContext $context, string $id, int $expectedVersion, ?string $reason): ReservationWaitlistEntry
    {
        return $this->update($context, $id, $expectedVersion, function (ReservationWaitlistEntry $e) use ($reason): void {
            if (! in_array($e->state, ['waiting', 'notified'], true)) {
                throw ReservationException::conflict(ReservationException::INVALID_STATE,
                    'Only a waiting or notified entry can be cancelled.');
            }
            $e->state = 'cancelled';
            $e->cancelled_at = now();
            $e->notes = $reason ?? $e->notes;
        }, 'waitlist.cancelled');
    }

    private function update(ReservationContext $context, string $id, int $expectedVersion, callable $mutate, string $action): ReservationWaitlistEntry
    {
        return DB::transaction(function () use ($context, $id, $expectedVersion, $mutate, $action): ReservationWaitlistEntry {
            $entry = ReservationWaitlistEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
                ?? throw ReservationException::notFound('The waiting-list entry was not found in this branch.');
            $this->assertVersion($entry->lock_version, $expectedVersion);
            $from = $entry->state;
            $mutate($entry);
            $entry->lock_version = $entry->lock_version + 1;
            $entry->save();
            $this->audit($context, 'waitlist_entry', $entry->id, $action, $from, $entry->state);

            return $entry->refresh();
        }, 3);
    }
}
