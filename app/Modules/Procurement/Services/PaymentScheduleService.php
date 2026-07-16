<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\PaymentSchedule;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payment schedule lifecycle: pending → paid / overdue.
 * Payment milestones can be attached to a PO, a vendor contract, or both.
 */
final class PaymentScheduleService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, PaymentSchedule> */
    public function list(ProcurementContext $context, ?string $status = null): Collection
    {
        return PaymentSchedule::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('supplier')
            ->orderBy('due_date')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): PaymentSchedule
    {
        return PaymentSchedule::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->with('supplier')
            ->first()
            ?? throw ProcurementException::notFound('Payment schedule not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, array $data): PaymentSchedule
    {
        return DB::transaction(function () use ($context, $data): PaymentSchedule {
            $schedule = PaymentSchedule::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'vendor_contract_id' => $data['vendor_contract_id'] ?? null,
                'reference' => $data['reference'],
                'status' => 'pending',
                'due_date' => $data['due_date'],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'IQD',
                'notes' => $data['notes'] ?? null,
                'lock_version' => 0,
            ]);
            $this->audit($context, 'payment_schedule', $schedule->id, 'created');

            return $schedule->load('supplier');
        }, 3);
    }

    public function markPaid(ProcurementContext $context, string $id, int $version): PaymentSchedule
    {
        return DB::transaction(function () use ($context, $id, $version): PaymentSchedule {
            $schedule = PaymentSchedule::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Payment schedule not found.');
            $this->assertVersion($schedule->lock_version, $version);
            if ($schedule->status !== 'pending') {
                throw ProcurementException::invalidState("Cannot mark a '{$schedule->status}' schedule as paid.");
            }
            $schedule->status = 'paid';
            $schedule->paid_at = now();
            $schedule->lock_version++;
            $schedule->save();
            $this->audit($context, 'payment_schedule', $schedule->id, 'paid');

            return $schedule->refresh()->load('supplier');
        }, 3);
    }
}
