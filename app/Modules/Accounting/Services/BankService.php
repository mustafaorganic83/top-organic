<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash & Bank account management, transaction ledger, and reconciliation.
 * Each bank account links to a GL account (asset) so the bank ledger keeps
 * sync with the general ledger via journal auto-posting.
 */
final class BankService
{
    use GuardsAccountingWrites;

    public function __construct(private readonly JournalService $journal) {}

    /** @return Collection<int, BankAccount> */
    public function list(AccountingContext $context): Collection
    {
        return BankAccount::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->orderBy('name')->get();
    }

    public function find(AccountingContext $context, string $id): BankAccount
    {
        return BankAccount::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->with('account')->first()
            ?? throw AccountingException::notFound('Bank account not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(AccountingContext $context, array $data): BankAccount
    {
        return DB::transaction(function () use ($context, $data): BankAccount {
            $this->assertTenantUnique(BankAccount::class, $context, 'code', (string) $data['code'], null);
            $opening = (int) ($data['opening_balance'] ?? 0);

            return BankAccount::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? $context->branchId,
                'account_id' => $data['account_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'iban' => $data['iban'] ?? null,
                'type' => $data['type'] ?? 'checking',
                'currency' => $data['currency'] ?? 'IQD',
                'opening_balance' => $opening,
                'current_balance' => $opening,
                'status' => $data['status'] ?? 'active',
                'lock_version' => 0,
            ]);
        }, 3);
    }

    /**
     * Statement: transactions for a bank account within an optional date range.
     *
     * @return Collection<int, BankTransaction>
     */
    public function statement(AccountingContext $context, string $bankId, ?string $from, ?string $to): Collection
    {
        return BankTransaction::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('bank_account_id', $bankId)
            ->when($from, fn ($q) => $q->where('transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('transaction_date', '<=', $to))
            ->orderBy('transaction_date')->orderBy('created_at')->get();
    }

    /**
     * Post a bank transaction (deposit or withdrawal) with an optional journal
     * entry link and update the running balance.
     *
     * @param  array<string, mixed>  $data
     */
    public function postTransaction(AccountingContext $context, string $bankId, array $data): BankTransaction
    {
        return DB::transaction(function () use ($context, $bankId, $data): BankTransaction {
            $bank = BankAccount::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($bankId)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Bank account not found.');
            $this->assertVersion($bank->lock_version, (int) $data['lock_version']);
            $debit = (int) ($data['debit_amount'] ?? 0);
            $credit = (int) ($data['credit_amount'] ?? 0);
            $bank->current_balance += $debit - $credit;
            $bank->lock_version++;
            $bank->save();

            return BankTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'bank_account_id' => $bankId,
                'transaction_date' => $data['transaction_date'],
                'description' => $data['description'] ?? null,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'running_balance' => $bank->current_balance,
                'reference' => $data['reference'] ?? null,
                'status' => 'unreconciled',
                'journal_entry_id' => $data['journal_entry_id'] ?? null,
            ]);
        }, 3);
    }

    /**
     * Mark a bank transaction as reconciled.
     */
    public function reconcile(AccountingContext $context, string $bankId, array $transactionIds): int
    {
        return BankTransaction::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('bank_account_id', $bankId)
            ->whereIn('id', $transactionIds)
            ->where('status', 'unreconciled')
            ->update(['status' => 'reconciled', 'reconciled_at' => now()]);
    }
}
