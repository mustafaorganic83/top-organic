<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\Supplier;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier (vendor) CRUD. Suppliers are the counterparties for Accounts Payable.
 * Each supplier can be linked to a specific AP GL account; if not set, the
 * default AP account from the Chart of Accounts is used.
 */
final class SupplierService
{
    use GuardsAccountingWrites;

    /** @return Collection<int, Supplier> */
    public function list(AccountingContext $context, ?string $status = null): Collection
    {
        return Supplier::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('name')->get();
    }

    public function find(AccountingContext $context, string $id): Supplier
    {
        return Supplier::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw AccountingException::notFound('Supplier not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(AccountingContext $context, array $data): Supplier
    {
        return DB::transaction(function () use ($context, $data): Supplier {
            $this->assertTenantUnique(Supplier::class, $context, 'code', (string) $data['code'], null);

            return Supplier::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'code' => $data['code'],
                'name' => $data['name'],
                'tax_number' => $data['tax_number'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'ap_account_id' => $data['ap_account_id'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'net30',
                'currency' => $data['currency'] ?? 'IQD',
                'status' => $data['status'] ?? 'active',
                'lock_version' => 0,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(AccountingContext $context, string $id, int $version, array $data): Supplier
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Supplier {
            $supplier = Supplier::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Supplier not found.');
            $this->assertVersion($supplier->lock_version, $version);
            $supplier->fill(array_intersect_key($data, array_flip([
                'name', 'tax_number', 'phone', 'email', 'address',
                'ap_account_id', 'payment_terms', 'currency', 'status',
            ])));
            $supplier->lock_version++;
            $supplier->save();

            return $supplier->refresh();
        }, 3);
    }
}
