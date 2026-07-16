<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\Rfq;
use App\Models\RfqItem;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Request for Quotation lifecycle: draft → sent → closed.
 * An RFQ captures what the branch needs; quotations are the supplier responses.
 */
final class RfqService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, Rfq> */
    public function list(ProcurementContext $context): Collection
    {
        return Rfq::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->with('items')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): Rfq
    {
        return Rfq::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->whereKey($id)
            ->with('items')
            ->first()
            ?? throw ProcurementException::notFound('RFQ not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, array $data): Rfq
    {
        return DB::transaction(function () use ($context, $data): Rfq {
            $this->assertBranchUnique(Rfq::class, $context, 'reference', (string) $data['reference'], null);
            $rfq = Rfq::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'reference' => $data['reference'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'requested_by' => $context->userId,
                'lock_version' => 0,
            ]);
            foreach (array_values($data['items'] ?? []) as $i => $line) {
                RfqItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'rfq_id' => $rfq->id,
                    'stock_item_id' => $line['stock_item_id'] ?? null,
                    'description' => $line['description'] ?? '',
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'required_date' => $line['required_date'] ?? null,
                    'notes' => $line['notes'] ?? null,
                    'line_number' => $i + 1,
                ]);
            }
            $this->audit($context, 'rfq', $rfq->id, 'created');

            return $rfq->load('items');
        }, 3);
    }

    public function transition(ProcurementContext $context, string $id, int $version, string $status): Rfq
    {
        return DB::transaction(function () use ($context, $id, $version, $status): Rfq {
            $rfq = Rfq::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('RFQ not found.');
            $this->assertVersion($rfq->lock_version, $version);
            $allowed = ['sent' => ['draft'], 'closed' => ['sent']];
            if (! isset($allowed[$status]) || ! in_array($rfq->status, $allowed[$status], true)) {
                throw ProcurementException::invalidTransition($rfq->status, $status);
            }
            $rfq->status = $status;
            if ($status === 'sent') {
                $rfq->issued_at = now();
            }
            if ($status === 'closed') {
                $rfq->closed_at = now();
            }
            $rfq->lock_version++;
            $rfq->save();
            $this->audit($context, 'rfq', $rfq->id, $status);

            return $rfq->load('items');
        }, 3);
    }
}
