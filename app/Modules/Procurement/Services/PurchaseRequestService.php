<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase request management from the procurement perspective.
 * Reuses the existing PurchaseRequest / PurchaseRequestItem models (from the
 * Inventory module schema) but applies procurement context and audit trail.
 * Status lifecycle: draft → submitted → approved / rejected.
 */
final class PurchaseRequestService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, PurchaseRequest> */
    public function list(ProcurementContext $context): Collection
    {
        return PurchaseRequest::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->with('items')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): PurchaseRequest
    {
        return PurchaseRequest::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)
            ->with('items')
            ->first()
            ?? throw ProcurementException::notFound('Purchase request not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($context, $data): PurchaseRequest {
            $this->assertBranchUnique(PurchaseRequest::class, $context, 'reference', (string) $data['reference'], null);
            $pr = PurchaseRequest::withoutGlobalScopes()->create([
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
            foreach (array_values($data['items'] ?? []) as $i => $line) {
                PurchaseRequestItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'purchase_request_id' => $pr->id,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'estimated_unit_cost_amount' => $line['estimated_unit_cost_amount'] ?? 0,
                    'line_number' => $i + 1,
                ]);
            }
            $this->audit($context, 'purchase_request', $pr->id, 'created');

            return $pr->load('items');
        }, 3);
    }

    public function approve(ProcurementContext $context, string $id, int $version): PurchaseRequest
    {
        return DB::transaction(function () use ($context, $id, $version): PurchaseRequest {
            $pr = PurchaseRequest::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Purchase request not found.');
            $this->assertVersion($pr->lock_version, $version);
            if (! in_array($pr->status, ['draft', 'submitted'], true)) {
                throw ProcurementException::alreadyApproved('Purchase request is already approved or rejected.');
            }
            $pr->status = 'approved';
            $pr->approved_by = $context->userId;
            $pr->approved_at = now();
            $pr->lock_version++;
            $pr->save();
            $this->audit($context, 'purchase_request', $pr->id, 'approved');

            return $pr->load('items');
        }, 3);
    }
}
