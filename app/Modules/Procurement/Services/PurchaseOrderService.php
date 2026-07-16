<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order lifecycle: draft → approved → sent → partially_received →
 * received → closed → cancelled. POs reference an optional awarded quotation.
 */
final class PurchaseOrderService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, PurchaseOrder> */
    public function list(ProcurementContext $context): Collection
    {
        return PurchaseOrder::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->with('items', 'supplier')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): PurchaseOrder
    {
        return PurchaseOrder::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)
            ->with('items', 'supplier')
            ->first()
            ?? throw ProcurementException::notFound('Purchase order not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($context, $data): PurchaseOrder {
            $this->assertBranchUnique(PurchaseOrder::class, $context, 'reference', (string) $data['reference'], null);
            $total = 0;
            foreach ($data['items'] ?? [] as $line) {
                $total += (int) ($line['total_amount'] ?? ((float) $line['quantity'] * (int) ($line['unit_price_amount'] ?? 0)));
            }
            $po = PurchaseOrder::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'supplier_id' => $data['supplier_id'],
                'quotation_id' => $data['quotation_id'] ?? null,
                'reference' => $data['reference'],
                'status' => 'draft',
                'currency' => $data['currency'] ?? 'IQD',
                'total_amount' => $data['total_amount'] ?? $total,
                'notes' => $data['notes'] ?? null,
                'created_by' => $context->userId,
                'lock_version' => 0,
            ]);
            foreach (array_values($data['items'] ?? []) as $i => $line) {
                PurchaseOrderItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'purchase_order_id' => $po->id,
                    'stock_item_id' => $line['stock_item_id'] ?? null,
                    'description' => $line['description'] ?? '',
                    'quantity' => $line['quantity'],
                    'quantity_received' => 0,
                    'unit' => $line['unit'],
                    'unit_price_amount' => $line['unit_price_amount'] ?? 0,
                    'total_amount' => $line['total_amount'] ?? ((float) $line['quantity'] * (int) ($line['unit_price_amount'] ?? 0)),
                    'line_number' => $i + 1,
                ]);
            }
            $this->audit($context, 'purchase_order', $po->id, 'created');

            return $po->load('items', 'supplier');
        }, 3);
    }

    public function transition(ProcurementContext $context, string $id, int $version, string $status): PurchaseOrder
    {
        return DB::transaction(function () use ($context, $id, $version, $status): PurchaseOrder {
            $po = PurchaseOrder::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Purchase order not found.');
            $this->assertVersion($po->lock_version, $version);
            $allowed = [
                'approved' => ['draft'],
                'sent' => ['approved'],
                'cancelled' => ['draft', 'approved'],
            ];
            if (! isset($allowed[$status]) || ! in_array($po->status, $allowed[$status], true)) {
                throw ProcurementException::invalidTransition($po->status, $status);
            }
            $po->status = $status;
            if ($status === 'approved') {
                $po->approved_by = $context->userId;
                $po->approved_at = now();
            }
            if ($status === 'sent') {
                $po->sent_at = now();
            }
            $po->lock_version++;
            $po->save();
            $this->audit($context, 'purchase_order', $po->id, $status);

            return $po->refresh()->load('items', 'supplier');
        }, 3);
    }
}
