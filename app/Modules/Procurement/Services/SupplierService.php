<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\Supplier;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier management for the Procurement module. Extends the existing AP
 * supplier entity with procurement-specific fields: category, quality rating,
 * and lead-time. Read operations reuse the shared suppliers table.
 */
final class SupplierService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, Supplier> */
    public function list(ProcurementContext $context, ?string $status = null): Collection
    {
        return Supplier::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): Supplier
    {
        return Supplier::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->first()
            ?? throw ProcurementException::notFound('Supplier not found.');
    }

    /** @param array<string, mixed> $data */
    public function update(ProcurementContext $context, string $id, int $version, array $data): Supplier
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Supplier {
            $supplier = Supplier::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Supplier not found.');
            $this->assertVersion($supplier->lock_version, $version);
            $supplier->fill(array_intersect_key($data, array_flip([
                'category', 'rating', 'lead_time_days', 'payment_terms', 'status',
            ])));
            $supplier->lock_version++;
            $supplier->save();
            $this->audit($context, 'supplier', $supplier->id, 'updated');

            return $supplier->refresh();
        }, 3);
    }
}
