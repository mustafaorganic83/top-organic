<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\BankAccount;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Payable: supplier bills (AP invoices) and payments.
 * Approving an invoice auto-posts a debit to the expense account and credit
 * to the supplier's AP account. Paying clears the AP liability and credits cash/bank.
 */
final class ApService
{
    use GuardsAccountingWrites;

    public function __construct(private readonly JournalService $journal) {}

    /** @return Collection<int, ApInvoice> */
    public function invoices(AccountingContext $context, ?string $supplierId = null, ?string $status = null): Collection
    {
        return ApInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('supplier')->orderByDesc('invoice_date')->get();
    }

    public function findInvoice(AccountingContext $context, string $id): ApInvoice
    {
        return ApInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->with('supplier', 'payments')->first()
            ?? throw AccountingException::notFound('AP invoice not found.');
    }

    /** @param array<string, mixed> $data */
    public function createInvoice(AccountingContext $context, array $data): ApInvoice
    {
        return DB::transaction(function () use ($context, $data): ApInvoice {
            $this->assertTenantUnique(ApInvoice::class, $context, 'reference', (string) $data['reference'], null);
            $total = (int) $data['total_amount'];
            $invoice = ApInvoice::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? $context->branchId,
                'supplier_id' => $data['supplier_id'],
                'reference' => $data['reference'],
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal_amount' => (int) ($data['subtotal_amount'] ?? $total),
                'tax_amount' => (int) ($data['tax_amount'] ?? 0),
                'total_amount' => $total,
                'paid_amount' => 0,
                'balance_amount' => $total,
                'currency' => $data['currency'] ?? 'IQD',
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'lock_version' => 0,
            ]);

            return $invoice;
        }, 3);
    }

    public function approveInvoice(AccountingContext $context, string $id, int $version): ApInvoice
    {
        return DB::transaction(function () use ($context, $id, $version): ApInvoice {
            $invoice = ApInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('AP invoice not found.');
            $this->assertVersion($invoice->lock_version, $version);
            if ($invoice->status !== 'draft') {
                throw AccountingException::invalidState('Only a draft invoice can be approved.');
            }
            $supplier = \App\Models\Supplier::withoutGlobalScopes()->whereKey($invoice->supplier_id)->first();
            // Auto-post journal entry (expense DR, AP CR)
            $jeRef = 'AP-'.strtoupper($invoice->reference);
            $lines = [
                ['account_id' => $this->defaultExpenseAccount($context)->id, 'debit_amount' => $invoice->total_amount, 'credit_amount' => 0, 'description' => 'AP: '.$invoice->reference],
            ];
            if ($supplier?->ap_account_id !== null) {
                $lines[] = ['account_id' => $supplier->ap_account_id, 'debit_amount' => 0, 'credit_amount' => $invoice->total_amount, 'description' => 'AP: '.$invoice->reference];
            }
            $je = $this->journal->create($context, [
                'reference' => $jeRef,
                'entry_date' => $invoice->invoice_date->toDateString(),
                'source' => 'ap_invoice',
                'source_type' => 'ap_invoice',
                'source_id' => $invoice->id,
                'description' => 'AP Invoice '.$invoice->reference,
                'lines' => $lines,
            ]);
            if (count($lines) >= 2) {
                $this->journal->post($context, $je->id, 0);
            }
            $invoice->status = 'approved';
            $invoice->journal_entry_id = $je->id;
            $invoice->approved_by = $context->userId;
            $invoice->approved_at = now();
            $invoice->lock_version++;
            $invoice->save();

            return $invoice->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function payInvoice(AccountingContext $context, string $invoiceId, array $data): ApPayment
    {
        return DB::transaction(function () use ($context, $invoiceId, $data): ApPayment {
            $invoice = ApInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($invoiceId)->lockForUpdate()->first()
                ?? throw AccountingException::notFound('AP invoice not found.');
            if (! in_array($invoice->status, ['approved', 'partial'], true)) {
                throw AccountingException::invalidState('Invoice must be approved before payment.');
            }
            $amount = (int) $data['amount'];
            if ($amount > $invoice->balance_amount) {
                throw AccountingException::invalid('Payment amount exceeds invoice balance.');
            }
            $payment = ApPayment::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? $context->branchId,
                'supplier_id' => $invoice->supplier_id,
                'ap_invoice_id' => $invoice->id,
                'reference' => (string) $data['reference'],
                'payment_date' => $data['payment_date'],
                'amount' => $amount,
                'method' => $data['method'] ?? 'bank_transfer',
                'currency' => $data['currency'] ?? 'IQD',
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
            ]);
            $invoice->paid_amount += $amount;
            $invoice->balance_amount -= $amount;
            $invoice->status = $invoice->balance_amount <= 0 ? 'paid' : 'partial';
            $invoice->save();
            // Debit AP account, credit cash/bank
            if (isset($data['bank_account_id'])) {
                $bankAcc = BankAccount::withoutGlobalScopes()->whereKey($data['bank_account_id'])->first();
                if ($bankAcc !== null) {
                    $bankAcc->current_balance -= $amount;
                    $bankAcc->save();
                }
            }

            return $payment;
        }, 3);
    }

    /**
     * AP Aging report: group outstanding invoices by overdue buckets.
     *
     * @return array<string, mixed>
     */
    public function aging(AccountingContext $context): array
    {
        $invoices = ApInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereIn('status', ['approved', 'partial'])->with('supplier')->get();
        $today = now()->toDateString();
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($invoices as $inv) {
            $days = max(0, (int) \Carbon\Carbon::parse($inv->due_date)->diffInDays($today, false));
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '90+',
            };
            $buckets[$bucket] += $inv->balance_amount;
        }

        return ['buckets' => $buckets, 'total' => array_sum($buckets)];
    }

    private function defaultExpenseAccount(AccountingContext $context): \App\Models\Account
    {
        return \App\Models\Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('type', 'expense')->where('is_leaf', true)->where('status', 'active')
            ->orderBy('code')->first()
            ?? throw AccountingException::notFound('No active expense account found. Please configure the Chart of Accounts.');
    }
}
