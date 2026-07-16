<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\GoodsReceipt;
use App\Models\Inspection;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quality inspection lifecycle: pending → passed / failed.
 * One inspection per goods receipt. Created automatically or manually.
 */
final class InspectionService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, Inspection> */
    public function list(ProcurementContext $context): Collection
    {
        return Inspection::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): Inspection
    {
        return Inspection::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->first()
            ?? throw ProcurementException::notFound('Inspection not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, string $goodsReceiptId, array $data): Inspection
    {
        return DB::transaction(function () use ($context, $goodsReceiptId, $data): Inspection {
            GoodsReceipt::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($goodsReceiptId)
                ->firstOrFail();

            // Only one inspection per goods receipt
            $existing = Inspection::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('goods_receipt_id', $goodsReceiptId)
                ->exists();
            if ($existing) {
                throw ProcurementException::conflict(
                    ProcurementException::IN_USE,
                    'An inspection already exists for this goods receipt.',
                );
            }

            $inspection = Inspection::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'goods_receipt_id' => $goodsReceiptId,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'findings' => $data['findings'] ?? null,
                'inspected_by' => $context->userId,
                'lock_version' => 0,
            ]);
            $this->audit($context, 'inspection', $inspection->id, 'created');

            return $inspection;
        }, 3);
    }

    public function complete(ProcurementContext $context, string $id, int $version, string $status): Inspection
    {
        return DB::transaction(function () use ($context, $id, $version, $status): Inspection {
            $inspection = Inspection::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Inspection not found.');
            $this->assertVersion($inspection->lock_version, $version);
            if ($inspection->status !== 'pending') {
                throw ProcurementException::invalidState("Inspection is already {$inspection->status}.");
            }
            if (! in_array($status, ['passed', 'failed'], true)) {
                throw ProcurementException::invalid("Status must be 'passed' or 'failed'.");
            }
            $inspection->status = $status;
            $inspection->inspected_at = now();
            $inspection->lock_version++;
            $inspection->save();
            $this->audit($context, 'inspection', $inspection->id, $status);

            return $inspection->refresh();
        }, 3);
    }
}
