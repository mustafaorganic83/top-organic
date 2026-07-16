<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\TableSession;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use Illuminate\Support\Facades\DB;

final class PosService
{
    public function __construct(private readonly SequenceNumberService $numbers) {}

    public function openShift(SalesContext $context): PosShift
    {
        return DB::transaction(function () use ($context): PosShift {
            $date = now()->toDateString();
            $sequence = $this->numbers->nextSequence($context, 'shift', $date);

            return PosShift::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'business_date' => $date, 'sequence' => $sequence, 'opened_by' => $context->userId,
                'state' => 'open', 'opened_at' => now(), 'lock_version' => 0,
            ]);
        }, 3);
    }

    public function closeShift(SalesContext $context, string $shiftId, int $expectedVersion): PosShift
    {
        return DB::transaction(function () use ($context, $shiftId, $expectedVersion): PosShift {
            $shift = $this->shiftForUpdate($context, $shiftId);
            $this->assertVersion($shift->lock_version, $expectedVersion);
            if ($shift->state !== 'open') {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only an open shift can be closed.');
            }
            $openDrawers = CashDrawerSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('pos_shift_id', $shift->id)->where('state', 'open')->exists();
            $openOrders = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('pos_shift_id', $shift->id)
                ->whereNotIn('state', ['settled', 'closed', 'cancelled', 'voided'])->exists();
            if ($openDrawers || $openOrders) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Close all drawer sessions and active orders before closing the shift.');
            }
            $shift->fill(['state' => 'closed', 'closed_by' => $context->userId, 'closed_at' => now(), 'lock_version' => $shift->lock_version + 1])->save();

            return $shift->refresh();
        }, 3);
    }

    public function openDrawerSession(
        SalesContext $context,
        string $shiftId,
        string $drawerId,
        string $currency,
        int $openingAmount,
    ): CashDrawerSession {
        $this->assertCurrencyAmount($currency, $openingAmount);

        return DB::transaction(function () use ($context, $shiftId, $drawerId, $currency, $openingAmount): CashDrawerSession {
            $shift = $this->shiftForUpdate($context, $shiftId);
            $drawer = CashDrawer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($drawerId)->lockForUpdate()->first();
            if ($shift->state !== 'open' || $drawer === null || $drawer->status !== 'active'
                || ($drawer->device_id !== null && $drawer->device_id !== $context->deviceId)) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'The shift or cash drawer is unavailable for this device.');
            }
            $alreadyOpen = CashDrawerSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('cash_drawer_id', $drawerId)->where('state', 'open')->exists();
            if ($alreadyOpen) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'The cash drawer already has an open session.');
            }

            return CashDrawerSession::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'cash_drawer_id' => $drawerId, 'pos_shift_id' => $shiftId, 'opened_by' => $context->userId,
                'currency' => $currency, 'opening_amount' => $openingAmount, 'state' => 'open',
                'opened_at' => now(), 'lock_version' => 0,
            ]);
        }, 3);
    }

    public function recordCashMovement(
        SalesContext $context,
        string $drawerSessionId,
        string $type,
        int $amount,
        string $currency,
        string $clientOperationId,
        ?string $reason = null,
    ): CashMovement {
        $existing = CashMovement::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('client_operation_id', $clientOperationId)->first();
        if ($existing !== null) {
            $expectedAmount = in_array($type, ['cash_out', 'refund'], true) ? -abs($amount) : $amount;
            if ($existing->cash_drawer_session_id !== $drawerSessionId || $existing->type !== $type
                || $existing->amount !== $expectedAmount || $existing->currency !== $currency) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The cash movement operation ID was reused with a different request.');
            }

            return $existing;
        }
        if (! in_array($type, ['cash_in', 'cash_out', 'sale', 'refund', 'adjustment'], true) || $amount === 0) {
            throw SalesException::invalid('Cash movement type and a non-zero integer amount are required.');
        }
        $this->assertCurrencyAmount($currency, abs($amount));
        if (in_array($type, ['cash_in', 'sale'], true) && $amount < 0) {
            throw SalesException::invalid('Cash-in and sale movements must be positive.');
        }
        if (in_array($type, ['cash_out', 'refund'], true) && $amount > 0) {
            $amount = -$amount;
        }

        return DB::transaction(function () use ($context, $drawerSessionId, $type, $amount, $currency, $clientOperationId, $reason): CashMovement {
            $session = $this->drawerSessionForUpdate($context, $drawerSessionId);
            if ($session->state !== 'open' || $session->currency !== $currency) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Cash movements require an open drawer session in the same currency.');
            }

            return CashMovement::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'cash_drawer_session_id' => $session->id, 'type' => $type, 'amount' => $amount,
                'currency' => $currency, 'reason' => $reason, 'client_operation_id' => $clientOperationId,
                'actor_id' => $context->userId, 'occurred_at' => now(),
            ]);
        }, 3);
    }

    public function reverseCashMovement(
        SalesContext $context,
        string $movementId,
        string $clientOperationId,
        string $reason,
    ): CashMovement {
        return DB::transaction(function () use ($context, $movementId, $clientOperationId, $reason): CashMovement {
            $original = CashMovement::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($movementId)->lockForUpdate()->first();
            if ($original === null) {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The cash movement was not found.');
            }
            $session = $this->drawerSessionForUpdate($context, $original->cash_drawer_session_id);
            if ($session->state !== 'open') {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'A movement can only be reversed while its drawer session is open.');
            }

            return CashMovement::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'client_operation_id' => $clientOperationId],
                ['cash_drawer_session_id' => $session->id, 'original_movement_id' => $original->id,
                    'type' => 'reversal', 'amount' => -$original->amount, 'currency' => $original->currency,
                    'reason' => $reason, 'actor_id' => $context->userId, 'occurred_at' => now()],
            );
        }, 3);
    }

    public function closeDrawerSession(
        SalesContext $context,
        string $sessionId,
        int $countedAmount,
        int $expectedVersion,
    ): CashDrawerSession {
        if ($countedAmount < 0) {
            throw SalesException::invalid('Counted cash cannot be negative.');
        }

        return DB::transaction(function () use ($context, $sessionId, $countedAmount, $expectedVersion): CashDrawerSession {
            $session = $this->drawerSessionForUpdate($context, $sessionId);
            $this->assertVersion($session->lock_version, $expectedVersion);
            if ($session->state !== 'open') {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only an open drawer session can be closed.');
            }
            $movementTotal = (int) CashMovement::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('cash_drawer_session_id', $session->id)->sum('amount');
            $expected = $session->opening_amount + $movementTotal;
            $session->fill([
                'expected_amount' => $expected, 'counted_amount' => $countedAmount,
                'variance_amount' => $countedAmount - $expected, 'state' => 'closed',
                'closed_by' => $context->userId, 'closed_at' => now(), 'lock_version' => $session->lock_version + 1,
            ])->save();

            return $session->refresh();
        }, 3);
    }

    public function openTableSession(SalesContext $context, string $tableId, int $guestCount): TableSession
    {
        return DB::transaction(function () use ($context, $tableId, $guestCount): TableSession {
            $table = DiningTable::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($tableId)->lockForUpdate()->first();
            if ($table === null || $table->status !== 'active' || $guestCount < 1 || $guestCount > $table->capacity) {
                throw SalesException::invalid('The table is unavailable or the guest count exceeds its capacity.');
            }
            $open = TableSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('dining_table_id', $tableId)->where('state', 'open')->exists();
            if ($open) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'The dining table already has an open session.');
            }

            return TableSession::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'dining_table_id' => $tableId, 'opened_by' => $context->userId,
                'guest_count' => $guestCount, 'state' => 'open', 'opened_at' => now(), 'lock_version' => 0,
            ]);
        }, 3);
    }

    public function closeTableSession(SalesContext $context, string $sessionId, int $expectedVersion): TableSession
    {
        return DB::transaction(function () use ($context, $sessionId, $expectedVersion): TableSession {
            $session = TableSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null) {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The table session was not found.');
            }
            $this->assertVersion($session->lock_version, $expectedVersion);
            $activeOrders = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('table_session_id', $session->id)
                ->whereNotIn('state', ['settled', 'closed', 'cancelled', 'voided'])->exists();
            if ($session->state !== 'open' || $activeOrders) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Settle or cancel active table orders before closing the table session.');
            }
            $session->fill(['state' => 'closed', 'closed_by' => $context->userId, 'closed_at' => now(), 'lock_version' => $session->lock_version + 1])->save();

            return $session->refresh();
        }, 3);
    }

    private function shiftForUpdate(SalesContext $context, string $id): PosShift
    {
        return PosShift::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The POS shift was not found.');
    }

    private function drawerSessionForUpdate(SalesContext $context, string $id): CashDrawerSession
    {
        return CashDrawerSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The drawer session was not found.');
    }

    private function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw SalesException::conflict(SalesException::STALE_VERSION, 'The record was changed by another operation.');
        }
    }

    private function assertCurrencyAmount(string $currency, int $amount): void
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency) || $amount < 0) {
            throw SalesException::invalid('Currency and a non-negative integer minor-unit amount are required.');
        }
    }
}
