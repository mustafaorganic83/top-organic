<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Models\Branch;
use App\Models\Device;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Procurement module feature tests: suppliers, evaluations, RFQ, quotation
 * comparison, purchase requests, purchase orders, goods receipts, inspections,
 * vendor contracts, and payment schedules.
 */
class ProcurementHttpTest extends TestCase
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
        $this->tenant = Tenant::create(['slug' => 'proc-http', 'name' => 'Procurement HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'HQ', 'name' => 'HQ']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id,
            'email' => 'proc@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'PROC-1', 'name' => 'Procurement Device', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'proc-device')]);
        $this->token = $this->login($this->user, $this->device->id);
    }

    // ── Suppliers ─────────────────────────────────────────────────────────

    public function test_list_and_show_supplier(): void
    {
        $supplierId = $this->createSupplier();

        $this->apiGet('/api/v1/procurement/suppliers')->assertStatus(200)
            ->assertJsonStructure(['data']);

        $this->apiGet("/api/v1/procurement/suppliers/{$supplierId}")->assertStatus(200)
            ->assertJsonPath('data.id', $supplierId);
    }

    public function test_update_supplier_procurement_fields(): void
    {
        $supplierId = $this->createSupplier();

        $this->apiPatch("/api/v1/procurement/suppliers/{$supplierId}", [
            'category' => 'produce', 'rating' => 85, 'lead_time_days' => 3, 'expected_version' => 0,
        ])->assertStatus(200)
            ->assertJsonPath('data.category', 'produce')
            ->assertJsonPath('data.rating', 85);
    }

    // ── Supplier Evaluations ──────────────────────────────────────────────

    public function test_create_and_list_supplier_evaluation(): void
    {
        $supplierId = $this->createSupplier();

        $r = $this->apiPost("/api/v1/procurement/suppliers/{$supplierId}/evaluations", [
            'score' => 88.5,
            'criteria' => ['quality' => 90, 'delivery' => 85],
            'notes' => 'Good supplier',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.supplier_id', $supplierId);

        $this->apiGet("/api/v1/procurement/suppliers/{$supplierId}/evaluations")
            ->assertStatus(200)->assertJsonStructure(['data']);
    }

    // ── RFQ ──────────────────────────────────────────────────────────────

    public function test_create_rfq_and_send(): void
    {
        $r = $this->apiPost('/api/v1/procurement/rfqs', [
            'reference' => 'RFQ-001',
            'items' => [
                ['description' => 'Tomatoes', 'quantity' => 100, 'unit' => 'kg'],
            ],
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $rfqId = $r->json('data.id');

        $this->apiPost("/api/v1/procurement/rfqs/{$rfqId}/send", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'sent');
    }

    // ── Quotations ────────────────────────────────────────────────────────

    public function test_submit_and_award_quotation(): void
    {
        $supplierId = $this->createSupplier();
        $rfqId = $this->createRfq();

        $r = $this->apiPost('/api/v1/procurement/quotations', [
            'rfq_id' => $rfqId,
            'supplier_id' => $supplierId,
            'reference' => 'QUOT-001',
            'total_amount' => 50000,
            'items' => [
                ['description' => 'Tomatoes', 'quantity' => 100, 'unit' => 'kg', 'unit_price_amount' => 500],
            ],
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'received');
        $quotationId = $r->json('data.id');

        $this->apiPost("/api/v1/procurement/quotations/{$quotationId}/award", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'awarded');

        $this->apiGet('/api/v1/procurement/quotations')->assertStatus(200);
    }

    public function test_shortlist_and_reject_quotation(): void
    {
        $supplierId = $this->createSupplier();

        $r = $this->apiPost('/api/v1/procurement/quotations', [
            'supplier_id' => $supplierId,
            'reference' => 'QUOT-002',
            'total_amount' => 30000,
            'items' => [
                ['description' => 'Onions', 'quantity' => 50, 'unit' => 'kg', 'unit_price_amount' => 600],
            ],
        ]);
        $r->assertStatus(201);
        $quotationId = $r->json('data.id');

        $this->apiPost("/api/v1/procurement/quotations/{$quotationId}/shortlist", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'shortlisted');

        $this->apiPost("/api/v1/procurement/quotations/{$quotationId}/reject", ['expected_version' => 1])
            ->assertStatus(200)->assertJsonPath('data.status', 'rejected');
    }

    // ── Purchase Requests ─────────────────────────────────────────────────

    public function test_create_and_approve_purchase_request(): void
    {
        $stockItemId = $this->createStockItem();

        $r = $this->apiPost('/api/v1/procurement/purchase-requests', [
            'reference' => 'PR-001',
            'items' => [
                ['stock_item_id' => $stockItemId, 'quantity' => 20, 'unit' => 'kg',
                    'estimated_unit_cost_amount' => 500],
            ],
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $prId = $r->json('data.id');

        $this->apiGet("/api/v1/procurement/purchase-requests/{$prId}")->assertStatus(200);

        $this->apiPost("/api/v1/procurement/purchase-requests/{$prId}/approve", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $this->apiGet('/api/v1/procurement/purchase-requests')->assertStatus(200);
    }

    // ── Purchase Orders ───────────────────────────────────────────────────

    public function test_create_approve_and_send_purchase_order(): void
    {
        $supplierId = $this->createSupplier();

        $r = $this->apiPost('/api/v1/procurement/purchase-orders', [
            'supplier_id' => $supplierId,
            'reference' => 'PO-001',
            'items' => [
                ['description' => 'Olive Oil', 'quantity' => 10, 'unit' => 'litre',
                    'unit_price_amount' => 5000, 'total_amount' => 50000],
            ],
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $poId = $r->json('data.id');

        $this->apiGet("/api/v1/procurement/purchase-orders/{$poId}")->assertStatus(200);

        $this->apiPost("/api/v1/procurement/purchase-orders/{$poId}/approve", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $this->apiPost("/api/v1/procurement/purchase-orders/{$poId}/send", ['expected_version' => 1])
            ->assertStatus(200)->assertJsonPath('data.status', 'sent');

        $this->apiGet('/api/v1/procurement/purchase-orders')->assertStatus(200);
    }

    // ── Receiving ─────────────────────────────────────────────────────────

    public function test_create_and_post_goods_receipt(): void
    {
        $r = $this->apiPost('/api/v1/procurement/receipts', [
            'reference' => 'GR-001',
            'items' => [
                ['description' => 'Flour', 'quantity_ordered' => 50, 'quantity_received' => 48,
                    'unit' => 'kg', 'unit_price_amount' => 200],
            ],
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $receiptId = $r->json('data.id');

        $this->apiGet("/api/v1/procurement/receipts/{$receiptId}")->assertStatus(200);

        $this->apiPost("/api/v1/procurement/receipts/{$receiptId}/post", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'posted');

        $this->apiGet('/api/v1/procurement/receipts')->assertStatus(200);
    }

    // ── Inspection ────────────────────────────────────────────────────────

    public function test_create_inspection_and_pass(): void
    {
        $r = $this->apiPost('/api/v1/procurement/receipts', [
            'reference' => 'GR-INS-001',
            'items' => [
                ['description' => 'Sugar', 'quantity_ordered' => 100, 'quantity_received' => 100,
                    'unit' => 'kg', 'unit_price_amount' => 150],
            ],
        ]);
        $r->assertStatus(201);
        $receiptId = $r->json('data.id');

        $ri = $this->apiPost("/api/v1/procurement/receipts/{$receiptId}/inspection", [
            'notes' => 'All good', 'findings' => ['color' => 'white'],
        ]);
        $ri->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $inspectionId = $ri->json('data.id');

        $this->apiGet("/api/v1/procurement/inspections/{$inspectionId}")->assertStatus(200);

        $this->apiPost("/api/v1/procurement/inspections/{$inspectionId}/pass", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'passed');

        $this->apiGet('/api/v1/procurement/inspections')->assertStatus(200);
    }

    public function test_create_inspection_and_fail(): void
    {
        $r = $this->apiPost('/api/v1/procurement/receipts', [
            'reference' => 'GR-INS-002',
            'items' => [
                ['description' => 'Pepper', 'quantity_ordered' => 20, 'quantity_received' => 20,
                    'unit' => 'kg', 'unit_price_amount' => 800],
            ],
        ]);
        $receiptId = $r->json('data.id');

        $ri = $this->apiPost("/api/v1/procurement/receipts/{$receiptId}/inspection", []);
        $ri->assertStatus(201);
        $inspectionId = $ri->json('data.id');

        $this->apiPost("/api/v1/procurement/inspections/{$inspectionId}/fail", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'failed');
    }

    // ── Vendor Contracts ──────────────────────────────────────────────────

    public function test_create_and_terminate_vendor_contract(): void
    {
        $supplierId = $this->createSupplier();

        $r = $this->apiPost('/api/v1/procurement/contracts', [
            'supplier_id' => $supplierId,
            'reference' => 'VC-001',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'value_amount' => 1000000,
            'currency' => 'IQD',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'active');
        $contractId = $r->json('data.id');

        $this->apiGet("/api/v1/procurement/contracts/{$contractId}")->assertStatus(200);

        $this->apiPost("/api/v1/procurement/contracts/{$contractId}/terminate", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'terminated');

        $this->apiGet('/api/v1/procurement/contracts')->assertStatus(200);
    }

    // ── Payment Schedules ─────────────────────────────────────────────────

    public function test_create_and_mark_payment_schedule_paid(): void
    {
        $supplierId = $this->createSupplier();

        $r = $this->apiPost('/api/v1/procurement/payment-schedules', [
            'supplier_id' => $supplierId,
            'reference' => 'PAY-001',
            'due_date' => '2026-08-01',
            'amount' => 250000,
            'currency' => 'IQD',
        ]);
        $r->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $scheduleId = $r->json('data.id');

        $this->apiGet("/api/v1/procurement/payment-schedules/{$scheduleId}")->assertStatus(200);

        $this->apiPost("/api/v1/procurement/payment-schedules/{$scheduleId}/pay", ['expected_version' => 0])
            ->assertStatus(200)->assertJsonPath('data.status', 'paid');

        $this->apiGet('/api/v1/procurement/payment-schedules')->assertStatus(200);
    }

    // ── Auth guard ────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/procurement/suppliers')->assertStatus(401);
    }

    // ── Private helpers ───────────────────────────────────────────────────

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

    /** Create a supplier using the accounting AP endpoint. */
    private function createSupplier(): string
    {
        $r = $this->apiPost('/api/v1/accounting/suppliers', [
            'code' => 'SUP-'.uniqid(), 'name' => 'Test Supplier',
        ]);

        return $r->json('data.id');
    }

    /** Create a draft RFQ via the procurement API. */
    private function createRfq(): string
    {
        $r = $this->apiPost('/api/v1/procurement/rfqs', [
            'reference' => 'RFQ-'.uniqid(),
            'items' => [
                ['description' => 'Item', 'quantity' => 10, 'unit' => 'kg'],
            ],
        ]);

        return $r->json('data.id');
    }

    /** Create a minimal stock item directly in the database. */
    private function createStockItem(): string
    {
        $item = StockItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Test Item',
            'stock_unit' => 'kg',
            'currency' => 'IQD',
        ]);

        return $item->id;
    }
}
