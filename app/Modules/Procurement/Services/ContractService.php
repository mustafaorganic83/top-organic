<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Models\VendorContract;
use App\Modules\Procurement\Data\ProcurementContext;
use App\Modules\Procurement\Exceptions\ProcurementException;
use App\Modules\Procurement\Services\Concerns\GuardsProcurementWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vendor contract lifecycle: active → expired / terminated.
 * Contracts define the agreed terms and value with a supplier.
 */
final class ContractService
{
    use GuardsProcurementWrites;

    /** @return Collection<int, VendorContract> */
    public function list(ProcurementContext $context, ?string $status = null): Collection
    {
        return VendorContract::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('supplier')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(ProcurementContext $context, string $id): VendorContract
    {
        return VendorContract::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($id)
            ->with('supplier')
            ->first()
            ?? throw ProcurementException::notFound('Vendor contract not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(ProcurementContext $context, array $data): VendorContract
    {
        return DB::transaction(function () use ($context, $data): VendorContract {
            $this->assertTenantUnique(VendorContract::class, $context, 'reference', (string) $data['reference'], null);
            $contract = VendorContract::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'supplier_id' => $data['supplier_id'],
                'reference' => $data['reference'],
                'status' => 'active',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'value_amount' => $data['value_amount'] ?? 0,
                'currency' => $data['currency'] ?? 'IQD',
                'terms' => $data['terms'] ?? null,
                'signed_at' => $data['signed_at'] ?? now(),
                'lock_version' => 0,
            ]);
            $this->audit($context, 'vendor_contract', $contract->id, 'created');

            return $contract->load('supplier');
        }, 3);
    }

    public function terminate(ProcurementContext $context, string $id, int $version): VendorContract
    {
        return DB::transaction(function () use ($context, $id, $version): VendorContract {
            $contract = VendorContract::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first()
                ?? throw ProcurementException::notFound('Vendor contract not found.');
            $this->assertVersion($contract->lock_version, $version);
            if ($contract->status !== 'active') {
                throw ProcurementException::invalidState("Cannot terminate a contract with status '{$contract->status}'.");
            }
            $contract->status = 'terminated';
            $contract->lock_version++;
            $contract->save();
            $this->audit($context, 'vendor_contract', $contract->id, 'terminated');

            return $contract->refresh()->load('supplier');
        }, 3);
    }
}
