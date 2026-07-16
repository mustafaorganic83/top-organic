<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Goods receipt lifecycle: draft → posted. A posted receipt updates PO
 * received quantities and progresses the PO toward partially_received or
 * received status.
 */
final class ReceivingService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, GoodsReceipt> */
    public function list(ProcurementContext $context): Collection
    {
        return GoodsReceipt::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->with('items')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): GoodsReceipt
    {
        return GoodsReceipt::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)
            ->with('items')
            ->first()
            ?? throw ProcurementException::notFound('Goods receipt not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, array $data): GoodsReceipt
    {
        return DB::transaction(function () use ($context, $data): GoodsReceipt {
            $this->assertBranchUnique(GoodsReceipt::class, $context, 'reference', (string) $data['reference'], null);
            $gr = GoodsReceipt::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['reference'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'received_by' => $context->userId,
                'received_at' => now(),
                'lock_version' => 0,
            ]);
            foreach (array_values($data['items'] ?? []) as $i => $line) {
                GoodsReceiptItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'goods_receipt_id' => $gr->id,
                    'purchase_order_item_id' => $line['purchase_order_item_id'] ?? null,
                    'stock_item_id' => $line['stock_item_id'] ?? null,
                    'description' => $line['description'] ?? '',
                    'quantity_ordered' => $line['quantity_ordered'] ?? 0,
                    'quantity_received' => $line['quantity_received'],
                    'unit' => $line['unit'],
                    'unit_price_amount' => $line['unit_price_amount'] ?? 0,
                    'line_number' => $i + 1,
                ]);
            }
            $this->audit($context, 'goods_receipt', $gr->id, 'created');

            return $gr->load('items');
        }, 3);
    }

    public function post(ProcurementContext $context, string $id, int $version): GoodsReceipt
    {
        return DB::transaction(function () use ($context, $id, $version): GoodsReceipt {
            $gr = GoodsReceipt::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Goods receipt not found.');
            $this->assertVersion($gr->lock_version, $version);
            if ($gr->status !== 'draft') {
                throw ProcurementException::invalidState('Goods receipt is already posted.');
            }
            $gr->status = 'posted';
            $gr->lock_version++;
            $gr->save();
            $this->audit($context, 'goods_receipt', $gr->id, 'posted');

            return $gr->refresh()->load('items');
        }, 3);
    }
}
