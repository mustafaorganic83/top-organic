<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Modules\Inventory\Data\InventoryContext;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\Concerns\GuardsInventoryWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase requests raised to replenish stock (draft -> submitted ->
 * approved / rejected). Can be raised manually or auto-generated from a stock
 * item's reorder point. Feeds procurement; receiving lands stock via the
 * batch-receipt endpoint.
 */
final class PurchaseRequestService
{
    use GuardsInventoryWrites;

    public function __construct(private readonly StockLedgerService $ledger) {}

    /** @return Collection<int, PurchaseRequest> */
    public function list(InventoryContext $context): Collection
    {
        return PurchaseRequest::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->with('items')->orderByDesc('created_at')->get();
    }

    public function show(InventoryContext $context, string $id): PurchaseRequest
    {
        return PurchaseRequest::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->with('items')->first()
            ?? throw InventoryException::notFound('The purchase request was not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(InventoryContext $context, array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($context, $data): PurchaseRequest {
            $this->assertBranchUnique(PurchaseRequest::class, $context, 'reference', (string) $data['reference'], null);
            $request = PurchaseRequest::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'reference' => $data['reference'],
                'status' => 'draft',
                'source' => $data['source'] ?? 'manual',
                'notes' => $data['notes'] ?? null,
                'requested_by' => $context->userId,
                'lock_version' => 0,
            ]);
            foreach (array_values($data['items']) as $i => $line) {
                PurchaseRequestItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'purchase_request_id' => $request->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'estimated_unit_cost_amount' => $line['estimated_unit_cost_amount'] ?? 0,
                    'line_number' => $i + 1,
                ]);
            }
            $this->audit($context, 'purchase_request', $request->id, 'created');

            return $request->load('items');
        }, 3);
    }

    /**
     * Generate a draft purchase request for every stock item at or below its
     * reorder point, sized to its reorder quantity. Returns null if nothing is
     * low.
     */
    public function generateFromReorder(InventoryContext $context, string $reference): ?PurchaseRequest
    {
        return DB::transaction(function () use ($context, $reference): ?PurchaseRequest {
            $low = $this->ledger->lowStock($context);
            if ($low->isEmpty()) {
                return null;
            }
            $items = $low->values()->map(fn ($item) => [
                'stock_item_id' => $item->id,
                'quantity' => (float) $item->reorder_quantity > 0 ? (float) $item->reorder_quantity : 1,
                'unit' => $item->stock_unit,
                'estimated_unit_cost_amount' => (int) $item->unit_cost_amount,
            ])->all();

            return $this->create($context, [
                'reference' => $reference,
                'source' => 'auto_reorder',
                'items' => $items,
            ]);
        }, 3);
    }

    public function transition(InventoryContext $context, string $id, int $version, string $status): PurchaseRequest
    {
        return DB::transaction(function () use ($context, $id, $version, $status): PurchaseRequest {
            $request = PurchaseRequest::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
                ?? throw InventoryException::notFound('The purchase request was not found.');
            $this->assertVersion($request->lock_version, $version);
            $allowed = [
                'submitted' => ['draft'],
                'approved' => ['submitted'],
                'rejected' => ['submitted'],
            ];
            if (! isset($allowed[$status]) || ! in_array($request->status, $allowed[$status], true)) {
                throw InventoryException::invalidState("Cannot move a {$request->status} request to {$status}.");
            }
            $request->status = $status;
            if ($status === 'submitted') {
                $request->submitted_at = now();
            }
            if ($status === 'approved' || $status === 'rejected') {
                $request->approved_by = $context->userId;
                $request->approved_at = now();
            }
            $request->lock_version++;
            $request->save();
            $this->audit($context, 'purchase_request', $request->id, $status);

            return $request->load('items');
        }, 3);
    }
}
