<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Modules\Accounting\Data\AccountingContext;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Services\Concerns\GuardsAccountingWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Journal Entry lifecycle: draft → posted → reversed. Validates debit/credit
 * balance before posting. Auto-posting creates journal entries from business
 * events (sales, payments, purchases) using configurable mapping rules.
 */
final class JournalService
{
    use GuardsAccountingWrites;

    /** @return Collection<int, JournalEntry> */
    public function list(AccountingContext $context, ?string $fiscalYear = null, ?string $status = null): Collection
    {
        return JournalEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($context->branchId, fn ($q) => $q->where('branch_id', $context->branchId))
            ->when($fiscalYear, fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('lines')->orderByDesc('entry_date')->get();
    }

    public function find(AccountingContext $context, string $id): JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->with('lines.account')->first()
            ?? throw AccountingException::notFound('Journal entry not found.');
    }

    /**
     * Create a draft journal entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(AccountingContext $context, array $data): JournalEntry
    {
        return DB::transaction(function () use ($context, $data): JournalEntry {
            $this->assertTenantUnique(JournalEntry::class, $context, 'reference', (string) $data['reference'], null);
            $entryDate = \Carbon\Carbon::parse($data['entry_date']);
            $entry = JournalEntry::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $data['branch_id'] ?? $context->branchId,
                'reference' => $data['reference'],
                'entry_date' => $entryDate->toDateString(),
                'fiscal_year' => $entryDate->format('Y'),
                'period_month' => (int) $entryDate->format('n'),
                'source' => $data['source'] ?? 'manual',
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'created_by' => $context->userId,
                'lock_version' => 0,
            ]);
            foreach (array_values($data['lines']) as $i => $line) {
                JournalEntryLine::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                    'line_number' => $i + 1,
                    'debit_amount' => (int) ($line['debit_amount'] ?? 0),
                    'credit_amount' => (int) ($line['credit_amount'] ?? 0),
                    'currency' => $line['currency'] ?? 'IQD',
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $entry->load('lines');
        }, 3);
    }

    /**
     * Post a draft journal entry: validate balance, set status=posted, update
     * budget actuals for expense/revenue accounts.
     */
    public function post(AccountingContext $context, string $id, int $version): JournalEntry
    {
        return DB::transaction(function () use ($context, $id, $version): JournalEntry {
            $entry = JournalEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->with('lines')->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Journal entry not found.');
            $this->assertVersion($entry->lock_version, $version);
            if ($entry->status === 'posted') {
                throw AccountingException::alreadyPosted();
            }
            if ($entry->status === 'reversed') {
                throw AccountingException::invalidState('A reversed entry cannot be posted.');
            }
            // Validate balance
            $totalDebit = $entry->lines->sum('debit_amount');
            $totalCredit = $entry->lines->sum('credit_amount');
            if ($totalDebit !== $totalCredit || $totalDebit === 0) {
                throw AccountingException::unbalanced();
            }
            $entry->status = 'posted';
            $entry->posted_by = $context->userId;
            $entry->posted_at = now();
            $entry->lock_version++;
            $entry->save();
            $this->updateBudgetActuals($context, $entry);

            return $entry->refresh()->load('lines');
        }, 3);
    }

    /**
     * Reverse a posted journal entry by creating a new counter-entry and
     * marking the original reversed.
     */
    public function reverse(AccountingContext $context, string $id, int $version, string $reference, string $description): JournalEntry
    {
        return DB::transaction(function () use ($context, $id, $version, $reference, $description): JournalEntry {
            $original = JournalEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->with('lines')->lockForUpdate()->first()
                ?? throw AccountingException::notFound('Journal entry not found.');
            $this->assertVersion($original->lock_version, $version);
            if ($original->status !== 'posted') {
                throw AccountingException::invalidState('Only a posted entry can be reversed.');
            }
            // Create reversing entry (swap debit/credit)
            $reversalData = [
                'reference' => $reference,
                'entry_date' => now()->toDateString(),
                'source' => 'reversal',
                'source_type' => 'journal_entry',
                'source_id' => $original->id,
                'description' => $description,
                'lines' => $original->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'cost_center_id' => $l->cost_center_id,
                    'project_id' => $l->project_id,
                    'debit_amount' => $l->credit_amount,
                    'credit_amount' => $l->debit_amount,
                    'currency' => $l->currency,
                    'description' => 'Reversal of '.$l->description,
                ])->all(),
            ];
            $reversal = $this->create($context, $reversalData);
            $reversal = $this->post($context, $reversal->id, 0);
            $original->status = 'reversed';
            $original->reversed_by = $reversal->id;
            $original->lock_version++;
            $original->save();

            return $reversal;
        }, 3);
    }

    /**
     * Auto-post a business event (sale, payment, purchase) using configured
     * auto_posting_rules for the event type. Called by the integration layer.
     *
     * @param  array<string, mixed>  $payload  event-specific data (amounts, ids, …)
     */
    public function autoPost(AccountingContext $context, string $eventType, array $payload, string $reference, string $sourceType, string $sourceId): ?JournalEntry
    {
        $rules = \App\Models\AutoPostingRule::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('event_type', $eventType)
            ->where('is_active', true)
            ->get();
        if ($rules->isEmpty()) {
            return null;
        }
        // Check idempotency: if this source already has a journal, skip.
        $existing = JournalEntry::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($context, $rules, $payload, $reference, $eventType, $sourceType, $sourceId): JournalEntry {
            $lines = [];
            foreach ($rules as $rule) {
                foreach ($rule->debit_mapping as $mapping) {
                    $amount = $this->resolveAmount($payload, $mapping);
                    if ($amount <= 0) {
                        continue;
                    }
                    $account = $this->resolveAccount($context, $mapping['account_code']);
                    $lines[] = ['account_id' => $account->id, 'debit_amount' => $amount, 'credit_amount' => 0,
                        'description' => $mapping['description'] ?? $eventType];
                }
                foreach ($rule->credit_mapping as $mapping) {
                    $amount = $this->resolveAmount($payload, $mapping);
                    if ($amount <= 0) {
                        continue;
                    }
                    $account = $this->resolveAccount($context, $mapping['account_code']);
                    $lines[] = ['account_id' => $account->id, 'debit_amount' => 0, 'credit_amount' => $amount,
                        'description' => $mapping['description'] ?? $eventType];
                }
            }
            if ($lines === []) {
                return null;
            }
            $entry = $this->create($context, [
                'reference' => $reference,
                'entry_date' => now()->toDateString(),
                'source' => 'auto_'.$eventType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => "Auto-posted: {$eventType}",
                'lines' => $lines,
            ]);

            return $this->post($context, $entry->id, 0);
        }, 3);
    }

    /** @param array<string, mixed> $mapping */
    private function resolveAmount(array $payload, array $mapping): int
    {
        $field = $mapping['amount_field'] ?? null;
        if ($field === null) {
            return (int) ($mapping['amount'] ?? 0);
        }

        return (int) ($payload[$field] ?? 0);
    }

    private function resolveAccount(AccountingContext $context, string $code): Account
    {
        return Account::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('code', $code)->where('status', 'active')->first()
            ?? throw AccountingException::notFound("Account [{$code}] not found or inactive.");
    }

    private function updateBudgetActuals(AccountingContext $context, JournalEntry $entry): void
    {
        foreach ($entry->lines as $line) {
            if ($line->debit_amount === 0 && $line->credit_amount === 0) {
                continue;
            }
            $account = Account::withoutGlobalScopes()->whereKey($line->account_id)->first();
            if ($account === null) {
                continue;
            }
            // For expense/cogs accounts, debit increases actuals; for revenue, credit.
            $delta = match ($account->type) {
                'expense', 'cogs' => $line->debit_amount - $line->credit_amount,
                'revenue' => $line->credit_amount - $line->debit_amount,
                default => 0,
            };
            if ($delta === 0) {
                continue;
            }
            \App\Models\Budget::withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->where('account_id', $line->account_id)
                ->where('fiscal_year', $entry->fiscal_year)
                ->where('period_month', $entry->period_month)
                ->increment('actual_amount', $delta);
        }
    }
}
