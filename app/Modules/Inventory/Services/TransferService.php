<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Modules\Inventory\Data\InventoryContext;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\Concerns\GuardsInventoryWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock transfers between two warehouses in the same branch. Dispatch issues
 * (deducts) the source warehouse and marks the transfer in_transit; receipt
 * adds the received quantities to the destination and closes the transfer.
 * In-transit stock belongs to neither warehouse's on-hand.
 */
final class TransferService
{
    use GuardsInventoryWrites;

    public function __construct(private readonly StockLedgerService $ledger) {}

    /** @return Collection<int, StockTransfer> */
    public function list(InventoryContext $context): Collection
    {
        return StockTransfer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->with('items')->orderByDesc('created_at')->get();
    }

    public function show(InventoryContext $context, string $id): StockTransfer
    {
        return StockTransfer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->with('items')->first()
            ?? throw InventoryException::notFound('The transfer was not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(InventoryContext $context, array $data): StockTransfer
    {
        return DB::transaction(function () use ($context, $data): StockTransfer {
            if ($data['source_warehouse_id'] === $data['destination_warehouse_id']) {
                throw InventoryException::invalid('Source and destination warehouses must differ.');
            }
            $this->assertBranchUnique(StockTransfer::class, $context, 'reference', (string) $data['reference'], null);
            $transfer = StockTransfer::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'reference' => $data['reference'],
                'source_warehouse_id' => $data['source_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'client_operation_id' => $data['client_operation_id'] ?? null,
                'lock_version' => 0,
            ]);
            foreach (array_values($data['items']) as $i => $line) {
                StockTransferItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'stock_transfer_id' => $transfer->id,
                    'stockable_type' => $line['stockable_type'] ?? 'stock_item',
                    'stockable_id' => $line['stockable_id'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_cost_amount' => $line['unit_cost_amount'] ?? 0,
                    'line_number' => $i + 1,
                ]);
            }
            $this->audit($context, 'stock_transfer', $transfer->id, 'created');

            return $transfer->load('items');
        }, 3);
    }

    public function dispatch(InventoryContext $context, string $id, int $version): StockTransfer
    {
        return DB::transaction(function () use ($context, $id, $version): StockTransfer {
            $transfer = $this->lockTransfer($context, $id);
            $this->assertVersion($transfer->lock_version, $version);
            if ($transfer->status !== 'draft') {
                throw InventoryException::invalidState('Only a draft transfer can be dispatched.');
            }
            foreach ($transfer->items as $item) {
                $this->ledger->issue($context, $transfer->source_warehouse_id, $item->stockable_type,
                    $item->stockable_id, (float) $item->quantity, $item->unit, 'transfer_out',
                    'stock_transfer', $transfer->id, $transfer->id.':out:'.$item->line_number);
            }
            $transfer->status = 'in_transit';
            $transfer->dispatched_by = $context->userId;
            $transfer->dispatched_at = now();
            $transfer->lock_version++;
            $transfer->save();
            $this->audit($context, 'stock_transfer', $transfer->id, 'dispatched');

            return $transfer->load('items');
        }, 3);
    }

    public function receive(InventoryContext $context, string $id, int $version): StockTransfer
    {
        return DB::transaction(function () use ($context, $id, $version): StockTransfer {
            $transfer = $this->lockTransfer($context, $id);
            $this->assertVersion($transfer->lock_version, $version);
            if ($transfer->status !== 'in_transit') {
                throw InventoryException::invalidState('Only an in-transit transfer can be received.');
            }
            foreach ($transfer->items as $item) {
                $this->ledger->receive($context, [
                    'warehouse_id' => $transfer->destination_warehouse_id,
                    'stockable_type' => $item->stockable_type,
                    'stockable_id' => $item->stockable_id,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'unit_cost_amount' => (int) $item->unit_cost_amount,
                    'batch_number' => 'TRF-'.$transfer->reference,
                    'client_operation_id' => $transfer->id.':in:'.$item->line_number,
                ]);
                $item->quantity_received = $item->quantity;
                $item->save();
            }
            $transfer->status = 'received';
            $transfer->received_by = $context->userId;
            $transfer->received_at = now();
            $transfer->lock_version++;
            $transfer->save();
            $this->audit($context, 'stock_transfer', $transfer->id, 'received');

            return $transfer->load('items');
        }, 3);
    }

    private function lockTransfer(InventoryContext $context, string $id): StockTransfer
    {
        return StockTransfer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->with('items')->lockForUpdate()->first()
            ?? throw InventoryException::notFound('The transfer was not found.');
    }
}
