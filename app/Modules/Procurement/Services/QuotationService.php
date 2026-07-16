<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quotation lifecycle: received → shortlisted → awarded / rejected.
 * Quotations are received from suppliers in response to an RFQ (optional).
 * The awarded quotation can be used to create a PO directly.
 */
final class QuotationService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, Quotation> */
    public function list(ProcurementContext $context, ?string $rfqId = null): Collection
    {
        return Quotation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($rfqId, fn ($q) => $q->where('rfq_id', $rfqId))
            ->with('items', 'supplier')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): Quotation
    {
        return Quotation::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->with('items', 'supplier')
            ->first()
            ?? throw ProcurementException::notFound('Quotation not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, array $data): Quotation
    {
        return DB::transaction(function () use ($context, $data): Quotation {
            $total = 0;
            foreach ($data['items'] ?? [] as $line) {
                $total += (int) ($line['total_amount'] ?? ((float) $line['quantity'] * (int) $line['unit_price_amount']));
            }
            $quotation = Quotation::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'rfq_id' => $data['rfq_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'reference' => $data['reference'],
                'status' => 'received',
                'currency' => $data['currency'] ?? 'IQD',
                'total_amount' => $data['total_amount'] ?? $total,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_at' => now(),
                'lock_version' => 0,
            ]);
            foreach (array_values($data['items'] ?? []) as $i => $line) {
                QuotationItem::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'quotation_id' => $quotation->id,
                    'stock_item_id' => $line['stock_item_id'] ?? null,
                    'description' => $line['description'] ?? '',
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price_amount' => $line['unit_price_amount'],
                    'total_amount' => $line['total_amount'] ?? ((float) $line['quantity'] * (int) $line['unit_price_amount']),
                    'line_number' => $i + 1,
                ]);
            }
            $this->audit($context, 'quotation', $quotation->id, 'received');

            return $quotation->load('items', 'supplier');
        }, 3);
    }

    public function transition(ProcurementContext $context, string $id, int $version, string $status): Quotation
    {
        return DB::transaction(function () use ($context, $id, $version, $status): Quotation {
            $quotation = Quotation::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Quotation not found.');
            $this->assertVersion($quotation->lock_version, $version);
            $allowed = [
                'shortlisted' => ['received'],
                'awarded' => ['received', 'shortlisted'],
                'rejected' => ['received', 'shortlisted'],
            ];
            if (! isset($allowed[$status]) || ! in_array($quotation->status, $allowed[$status], true)) {
                throw ProcurementException::invalidTransition($quotation->status, $status);
            }
            $quotation->status = $status;
            $quotation->lock_version++;
            $quotation->save();
            $this->audit($context, 'quotation', $quotation->id, $status);

            return $quotation->refresh()->load('items', 'supplier');
        }, 3);
    }
}
