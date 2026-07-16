<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\Account;
use App\Models\CostCenter;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chart of Accounts CRUD and Cost Center management.
 * Accounts form a tree (parent_id); only leaf nodes with allow_direct_posting
 * accept journal lines. System accounts cannot be deleted.
 */
final class ChartOfAccountsService
{
    use GuardsAccountingWrites;

    /** @return Collection<int, Account> */
    public function list(AccountingContext $context, ?string $type = null): Collection
    {
        return Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->orderBy('code')->get();
    }

    public function find(AccountingContext $context, string $id): Account
    {
        return Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->first()
            ?? throw AccountingException::notFound('Account not found.');
    }

    public function findByCode(AccountingContext $context, string $code): Account
    {
        return Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('code', $code)->first()
            ?? throw AccountingException::notFound("Account [{$code}] not found.");
    }

    /** @param array<string, mixed> $data */
    public function create(AccountingContext $context, array $data): Account
    {
        return DB::transaction(function () use ($context, $data): Account {
            $this->assertTenantUnique(Account::class, $context, 'code', (string) $data['code'], null);
            if (isset($data['parent_id'])) {
                $parent = $this->find($context, (string) $data['parent_id']);
                // Parent becomes a header node once it has children
                $parent->is_leaf = false;
                $parent->allow_direct_posting = false;
                $parent->save();
            }

            return Account::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'subtype' => $data['subtype'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'is_leaf' => true,
                'is_system' => false,
                'allow_direct_posting' => (bool) ($data['allow_direct_posting'] ?? true),
                'currency' => $data['currency'] ?? 'IQD',
                'status' => $data['status'] ?? 'active',
                'lock_version' => 0,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(AccountingContext $context, string $id, int $version, array $data): Account
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Account {
            $account = Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Account not found.');
            $this->assertVersion($account->lock_version, $version);
            if (isset($data['code']) && $data['code'] !== $account->code) {
                $this->assertTenantUnique(Account::class, $context, 'code', (string) $data['code'], $id);
            }
            $account->fill(array_intersect_key($data, array_flip([
                'name', 'subtype', 'status', 'allow_direct_posting', 'currency', 'code',
            ])));
            $account->lock_version++;
            $account->save();

            return $account->refresh();
        }, 3);
    }

    public function delete(AccountingContext $context, string $id): void
    {
        DB::transaction(function () use ($context, $id): void {
            $account = Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Account not found.');
            if ($account->is_system) {
                throw AccountingException::invalid('System accounts cannot be deleted.');
            }
            $hasLines = \App\Models\JournalEntryLine::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)->where('account_id', $id)->exists();
            if ($hasLines) {
                throw AccountingException::inUse('Account has posted journal lines and cannot be deleted.');
            }
            $account->delete();
        }, 3);
    }

    // --- Cost Centers ---

    /** @return Collection<int, CostCenter> */
    public function costCenters(AccountingContext $context): Collection
    {
        return CostCenter::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->orderBy('code')->get();
    }

    /** @param array<string, mixed> $data */
    public function createCostCenter(AccountingContext $context, array $data): CostCenter
    {
        return DB::transaction(function () use ($context, $data): CostCenter {
            $this->assertTenantUnique(CostCenter::class, $context, 'code', (string) $data['code'], null);

            return CostCenter::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'] ?? 'branch',
                'parent_id' => $data['parent_id'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'lock_version' => 0,
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateCostCenter(AccountingContext $context, string $id, int $version, array $data): CostCenter
    {
        return DB::transaction(function () use ($context, $id, $version, $data): CostCenter {
            $cc = CostCenter::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Cost center not found.');
            $this->assertVersion($cc->lock_version, $version);
            $cc->fill(array_intersect_key($data, array_flip(['name', 'type', 'is_active'])));
            $cc->lock_version++;
            $cc->save();

            return $cc->refresh();
        }, 3);
    }
}
