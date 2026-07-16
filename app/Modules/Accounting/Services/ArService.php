<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\ArInvoice;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Receivable: customer invoices and AR aging.
 * Can be linked to an existing sales Invoice or created standalone.
 * Auto-posts DR Receivable / CR Revenue when an AR invoice is created.
 */
final class ArService
{
    use GuardsAccountingWrites;

    public function __construct(private readonly JournalService $journal) {}

    /** @return Collection<int, ArInvoice> */
    public function invoices(AccountingContext $context, ?string $customerId = null, ?string $status = null): Collection
    {
        return ArInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('customer')->orderByDesc('invoice_date')->get();
    }

    public function findInvoice(AccountingContext $context, string $id): ArInvoice
    {
        return ArInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->with('customer', 'journalEntry')->first()
            ?? throw AccountingException::notFound('AR invoice not found.');
    }

    /** @param array<string, mixed> $data */
    public function createInvoice(AccountingContext $context, array $data): ArInvoice
    {
        return DB::transaction(function () use ($context, $data): ArInvoice {
            $this->assertTenantUnique(ArInvoice::class, $context, 'reference', (string) $data['reference'], null);
            $total = (int) $data['total_amount'];
            $invoice = ArInvoice::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? $context->branchId,
                'customer_id' => $data['customer_id'] ?? null,
                'order_invoice_id' => $data['order_invoice_id'] ?? null,
                'reference' => $data['reference'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'total_amount' => $total,
                'paid_amount' => 0,
                'balance_amount' => $total,
                'currency' => $data['currency'] ?? 'IQD',
                'status' => 'open',
            ]);
            // Auto-post: DR Accounts Receivable / CR Revenue
            $arAccount = $this->arAccount($context);
            $revenueAccount = $this->revenueAccount($context);
            $lines = [
                ['account_id' => $arAccount->id, 'debit_amount' => $total, 'credit_amount' => 0, 'description' => 'AR: '.$invoice->reference],
                ['account_id' => $revenueAccount->id, 'debit_amount' => 0, 'credit_amount' => $total, 'description' => 'AR: '.$invoice->reference],
            ];
            $je = $this->journal->create($context, [
                'reference' => 'AR-'.$invoice->reference,
                'entry_date' => $invoice->invoice_date->toDateString(),
                'source' => 'ar_invoice',
                'source_type' => 'ar_invoice',
                'source_id' => $invoice->id,
                'description' => 'AR Invoice '.$invoice->reference,
                'lines' => $lines,
            ]);
            $this->journal->post($context, $je->id, 0);
            $invoice->journal_entry_id = $je->id;
            $invoice->save();

            return $invoice->refresh();
        }, 3);
    }

    /**
     * AR Aging report: group outstanding receivables by overdue buckets.
     *
     * @return array<string, mixed>
     */
    public function aging(AccountingContext $context): array
    {
        $invoices = ArInvoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereIn('status', ['open', 'partial'])->get();
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

    private function arAccount(AccountingContext $context): \App\Models\Account
    {
        return \App\Models\Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('type', 'asset')->where('subtype', 'receivable')->where('status', 'active')->first()
            ?? throw AccountingException::notFound('No active Accounts Receivable account found.');
    }

    private function revenueAccount(AccountingContext $context): \App\Models\Account
    {
        return \App\Models\Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('type', 'revenue')->where('is_leaf', true)->where('status', 'active')
            ->orderBy('code')->first()
            ?? throw AccountingException::notFound('No active revenue account found.');
    }
}
