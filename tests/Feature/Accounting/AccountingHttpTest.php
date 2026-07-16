<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Accounting ERP feature tests: Chart of Accounts, Journal Entries (create,
 * post, reverse), AP (invoice → approve → pay), AR (invoice + auto-journal),
 * Bank accounts, Budget (create, approve, variance), Projects, Financial reports.
 */
class AccountingHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $user;

    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'acc-http', 'name' => 'Accounting HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'HQ', 'name' => 'HQ']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'acc@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'ACC-1', 'name' => 'Accounting Device', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'acc-device')]);
        $this->token = $this->login($this->user, $this->device->id);
    }

    // ── Chart of Accounts ──────────────────────────────────────────────────

    public function test_create_and_list_accounts(): void
    {
        $r = $this->apiPost('/api/v1/accounting/accounts', [
            'code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'cash',
        ]);
        $r->assertStatus(201);
        $this->assertEquals('1000', $r->json('data.code'));

        $this->apiGet('/api/v1/accounting/accounts')->assertStatus(200)
            ->assertJsonPath('data.0.code', '1000');
    }

    public function test_update_account(): void
    {
        $id = $this->createAccount('2000', 'AP Control', 'liability', 'payable');

        $this->apiPatch("/api/v1/accounting/accounts/{$id}", [
            'name' => 'Accounts Payable', 'expected_version' => 0,
        ])->assertStatus(200)->assertJsonPath('data.name', 'Accounts Payable');
    }

    public function test_cannot_delete_account_with_journal_lines(): void
    {
        $debitId = $this->createAccount('1010', 'Receivables', 'asset', 'receivable');
        $creditId = $this->createAccount('4000', 'Revenue', 'revenue');
        $this->createAndPostJournal($debitId, $creditId, 5000);

        $this->apiDelete("/api/v1/accounting/accounts/{$debitId}")->assertStatus(409);
    }

    // ── Journal Entries ────────────────────────────────────────────────────

    public function test_create_draft_journal_entry(): void
    {
        $dr = $this->createAccount('1100', 'Bank', 'asset', 'bank');
        $cr = $this->createAccount('4100', 'Sales Revenue', 'revenue');

        $r = $this->apiPost('/api/v1/accounting/journals', [
            'reference' => 'JE-001', 'entry_date' => '2026-01-15',
            'lines' => [
                ['account_id' => $dr, 'debit_amount' => 10000, 'credit_amount' => 0],
                ['account_id' => $cr, 'debit_amount' => 0, 'credit_amount' => 10000],
            ],
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
    }

    public function test_post_journal_entry(): void
    {
        $dr = $this->createAccount('1200', 'Petty Cash', 'asset', 'cash');
        $cr = $this->createAccount('4200', 'Beverage Revenue', 'revenue');
        $jeId = $this->createJournalId($dr, $cr, 3000);

        $this->apiPost("/api/v1/accounting/journals/{$jeId}/post", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'posted');
    }

    public function test_unbalanced_journal_cannot_be_posted(): void
    {
        $dr = $this->createAccount('1300', 'Prepaid', 'asset');
        $cr = $this->createAccount('4300', 'Other Revenue', 'revenue');
        // Intentionally unbalanced: debit 500, credit 300
        $r = $this->apiPost('/api/v1/accounting/journals', [
            'reference' => 'JE-BAD', 'entry_date' => '2026-01-16',
            'lines' => [
                ['account_id' => $dr, 'debit_amount' => 500, 'credit_amount' => 0],
                ['account_id' => $cr, 'debit_amount' => 0, 'credit_amount' => 300],
            ],
        ]);
        $jeId = $r->json('data.id');
        $this->apiPost("/api/v1/accounting/journals/{$jeId}/post", ['expected_version' => 0])
            ->assertStatus(422);
    }

    public function test_reverse_posted_journal(): void
    {
        $dr = $this->createAccount('1400', 'Equipment', 'asset');
        $cr = $this->createAccount('2100', 'Loans Payable', 'liability');
        $jeId = $this->createAndPostJournal($dr, $cr, 50000);

        $this->apiPost("/api/v1/accounting/journals/{$jeId}/reverse", [
            'expected_version' => 1,
            'reference' => 'JE-REV-001',
            'description' => 'Reversing equipment entry',
        ])->assertStatus(201)->assertJsonPath('data.source', 'reversal');

        $this->apiGet("/api/v1/accounting/journals/{$jeId}")
            ->assertJsonPath('data.status', 'reversed');
    }

    // ── Accounts Payable ───────────────────────────────────────────────────

    public function test_ap_invoice_lifecycle(): void
    {
        $this->createAccount('5000', 'Food Cost', 'expense');
        $this->createAccount('2200', 'Accounts Payable', 'liability', 'payable');
        $supplierId = $this->createSupplier();

        // Create invoice
        $r = $this->apiPost('/api/v1/accounting/ap/invoices', [
            'supplier_id' => $supplierId, 'reference' => 'BILL-001',
            'invoice_date' => '2026-01-10', 'due_date' => '2026-02-10',
            'total_amount' => 20000,
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $invoiceId = $r->json('data.id');

        // Approve (auto-posts journal if expense+AP accounts exist)
        $this->apiPost("/api/v1/accounting/ap/invoices/{$invoiceId}/approve", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');

        // Pay
        $this->apiPost("/api/v1/accounting/ap/invoices/{$invoiceId}/pay", [
            'reference' => 'PMT-001', 'payment_date' => '2026-01-15',
            'amount' => 20000, 'method' => 'bank_transfer',
        ])->assertStatus(201);

        // Invoice should be paid
        $this->apiGet("/api/v1/accounting/ap/invoices/{$invoiceId}")
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_ap_aging_report(): void
    {
        $this->apiGet('/api/v1/accounting/ap/aging')->assertStatus(200)
            ->assertJsonPath('data.buckets.current', 0);
    }

    // ── Accounts Receivable ────────────────────────────────────────────────

    public function test_ar_invoice_creates_journal_entry(): void
    {
        $this->createAccount('1500', 'Accounts Receivable', 'asset', 'receivable');
        $this->createAccount('4400', 'Food Revenue', 'revenue');

        $r = $this->apiPost('/api/v1/accounting/ar/invoices', [
            'reference' => 'ARINV-001', 'invoice_date' => '2026-01-20',
            'due_date' => '2026-02-20', 'total_amount' => 15000,
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'open');
        $this->assertNotNull($r->json('data.journal_entry_id'));
    }

    public function test_ar_aging_report(): void
    {
        $this->apiGet('/api/v1/accounting/ar/aging')->assertStatus(200)
            ->assertJsonPath('data.total', 0);
    }

    // ── Bank Accounts ─────────────────────────────────────────────────────

    public function test_create_bank_account_and_statement(): void
    {
        $glId = $this->createAccount('1600', 'Main Bank', 'asset', 'bank');

        $r = $this->apiPost('/api/v1/accounting/bank-accounts', [
            'code' => 'BANK-001', 'name' => 'Main Checking', 'account_id' => $glId,
            'type' => 'checking', 'opening_balance' => 100000,
        ]);
        $r->assertStatus(201)->assertJsonPath('data.current_balance', 100000);
        $bankId = $r->json('data.id');

        $this->apiGet("/api/v1/accounting/bank-accounts/{$bankId}/statement")
            ->assertStatus(200)->assertJsonPath('data', []);
    }

    // ── Budget ────────────────────────────────────────────────────────────

    public function test_budget_lifecycle_and_variance(): void
    {
        $accountId = $this->createAccount('5100', 'Staff Wages', 'expense', 'labour');

        $r = $this->apiPost('/api/v1/accounting/budgets', [
            'account_id' => $accountId, 'fiscal_year' => '2026',
            'period_month' => 1, 'budgeted_amount' => 50000,
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $budgetId = $r->json('data.id');

        $this->apiPost("/api/v1/accounting/budgets/{$budgetId}/approve")->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->apiGet('/api/v1/accounting/budgets/variance?fiscal_year=2026&period_month=1')
            ->assertStatus(200)->assertJsonPath('data.fiscal_year', '2026');
    }

    // ── Projects ──────────────────────────────────────────────────────────

    public function test_project_crud(): void
    {
        $r = $this->apiPost('/api/v1/accounting/projects', [
            'code' => 'PROJ-001', 'name' => 'Branch Renovation',
            'budget_amount' => 500000, 'start_date' => '2026-03-01',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.code', 'PROJ-001');
        $projId = $r->json('data.id');

        $this->apiPatch("/api/v1/accounting/projects/{$projId}", [
            'name' => 'Branch Renovation Phase 1', 'expected_version' => 0,
        ])->assertStatus(200)->assertJsonPath('data.name', 'Branch Renovation Phase 1');
    }

    // ── Financial Reports ─────────────────────────────────────────────────

    public function test_trial_balance(): void
    {
        $this->apiGet('/api/v1/accounting/reports/trial-balance?fiscal_year=2026')
            ->assertStatus(200)->assertJsonPath('data.fiscal_year', '2026')
            ->assertJsonPath('data.is_balanced', true);
    }

    public function test_income_statement(): void
    {
        $this->apiGet('/api/v1/accounting/reports/income-statement?fiscal_year=2026')
            ->assertStatus(200)->assertJsonPath('data.fiscal_year', '2026');
    }

    public function test_balance_sheet(): void
    {
        $this->apiGet('/api/v1/accounting/reports/balance-sheet?fiscal_year=2026')
            ->assertStatus(200)->assertJsonPath('data.is_balanced', true);
    }

    public function test_cash_flow_statement(): void
    {
        $this->apiGet('/api/v1/accounting/reports/cash-flow?fiscal_year=2026')
            ->assertStatus(200)->assertJsonPath('data.fiscal_year', '2026');
    }

    public function test_restaurant_profitability(): void
    {
        $this->apiGet('/api/v1/accounting/reports/profitability?fiscal_year=2026')
            ->assertStatus(200)->assertJsonPath('data.gross_profit', 0);
    }

    public function test_branch_accounting(): void
    {
        $this->apiGet('/api/v1/accounting/reports/branch-accounting?fiscal_year=2026')
            ->assertStatus(200)->assertJsonPath('data.fiscal_year', '2026');
    }

    public function test_general_ledger(): void
    {
        $accountId = $this->createAccount('3000', 'Retained Earnings', 'equity');
        $this->apiGet("/api/v1/accounting/reports/general-ledger?account_id={$accountId}")
            ->assertStatus(200)->assertJsonPath('data.closing_balance', 0);
    }

    // ── RBAC ──────────────────────────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/accounting/accounts')->assertStatus(401);
    }

    public function test_cost_center_crud(): void
    {
        $r = $this->apiPost('/api/v1/accounting/cost-centers', [
            'code' => 'CC-KITCHEN', 'name' => 'Kitchen', 'type' => 'department',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.code', 'CC-KITCHEN');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function login(User $user, string $deviceId): string
    {
        $r = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email, 'password' => 'Password123',
            'tenant_slug' => $this->tenant->slug, 'device_id' => $deviceId,
        ]);

        return $r->json('data.access_token') ?? '';
    }

    private function apiGet(string $url): TestResponse
    {
        return $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->getJson($url);
    }

    private function apiPost(string $url, array $data = []): TestResponse
    {
        return $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->postJson($url, $data);
    }

    private function apiPatch(string $url, array $data): TestResponse
    {
        return $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->patchJson($url, $data);
    }

    private function apiDelete(string $url): TestResponse
    {
        return $this->withToken($this->token)->withHeaders([
            'X-Branch-Id' => $this->branch->id, 'X-Device-Id' => $this->device->id,
        ])->deleteJson($url);
    }

    private function createAccount(string $code, string $name, string $type, ?string $subtype = null): string
    {
        $r = $this->apiPost('/api/v1/accounting/accounts', array_filter([
            'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype,
        ]));

        return $r->json('data.id');
    }

    private function createJournalId(string $drId, string $crId, int $amount): string
    {
        $r = $this->apiPost('/api/v1/accounting/journals', [
            'reference' => 'JE-'.uniqid(), 'entry_date' => '2026-01-01',
            'lines' => [
                ['account_id' => $drId, 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $crId, 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        ]);

        return $r->json('data.id');
    }

    private function createAndPostJournal(string $drId, string $crId, int $amount): string
    {
        $jeId = $this->createJournalId($drId, $crId, $amount);
        $this->apiPost("/api/v1/accounting/journals/{$jeId}/post", ['expected_version' => 0]);

        return $jeId;
    }

    private function createSupplier(): string
    {
        $r = $this->apiPost('/api/v1/accounting/suppliers', [
            'code' => 'SUP-'.uniqid(), 'name' => 'Test Supplier',
        ]);

        return $r->json('data.id');
    }
}
