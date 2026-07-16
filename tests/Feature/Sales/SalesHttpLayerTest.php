<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\BranchCatalogItem;
use App\Models\BranchPaymentMethod;
use App\Models\CashDrawer;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\KdsStation;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesHttpLayerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $user;

    private Device $device;

    private string $token;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::create(['slug' => 'sales-http', 'name' => 'Sales HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'sales@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('admin');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'POS-HTTP', 'name' => 'POS HTTP', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'pos-http')]);
        $this->token = $this->login($this->user, $this->device->id);
        $this->variant = $this->catalog();
    }

    public function test_permissions_trusted_scope_device_and_validation_are_enforced(): void
    {
        $ordinary = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ordinary@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($ordinary);
        $ordinaryToken = $this->login($ordinary, $this->device->id);
        $this->withToken($ordinaryToken)->getJson('/api/v1/sales/catalog')->assertForbidden()
            ->assertJsonPath('error.code', 'PERMISSION_DENIED')->assertJsonStructure(['request_id']);

        $this->withToken($this->token)->postJson('/api/v1/sales/catalog/barcode/scan', [
            'barcode' => $this->variant->barcode, 'tenant_id' => (string) Str::ulid(),
            'branch_id' => (string) Str::ulid(), 'device_id' => (string) Str::ulid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $withoutDevice = $this->login($this->user, null);
        $this->withToken($withoutDevice)->postJson('/api/v1/pos/shifts')
            ->assertForbidden()->assertJsonPath('error.code', 'POS_DEVICE_REQUIRED');

        $desktop = Device::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'code' => 'DESKTOP-HTTP',
            'name' => 'Desktop HTTP', 'type' => 'desktop', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'desktop-http'),
        ]);
        $desktopToken = $this->login($this->user, $desktop->id);
        $this->withToken($desktopToken)->postJson('/api/v1/pos/shifts')
            ->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_AUTHORIZED');

        $revokedDeviceToken = $this->login($this->user, $this->device->id);
        $this->device->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->withToken($revokedDeviceToken)->postJson('/api/v1/pos/shifts')->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_all_order_channels_barcode_and_strict_money_quantity_contracts(): void
    {
        $scan = $this->withToken($this->token)->postJson('/api/v1/sales/catalog/barcode/scan', [
            'barcode' => $this->variant->barcode,
        ])->assertOk()->assertJsonPath('data.unit_price_amount', 1000);
        $this->assertSame($this->variant->id, $scan->json('data.variant_id'));

        $floor = Floor::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'GROUND', 'name' => 'Ground']);
        $table = DiningTable::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'floor_id' => $floor->id, 'code' => 'T1', 'name' => 'Table 1', 'capacity' => 4]);
        $session = $this->withToken($this->token)->postJson('/api/v1/pos/table-sessions', [
            'table_id' => $table->id, 'guest_count' => 2,
        ])->assertCreated()->json('data.id');

        foreach (['dine_in', 'takeaway', 'delivery', 'online'] as $type) {
            $payload = ['type' => $type, 'currency' => 'IQD', 'client_operation_id' => (string) Str::ulid()];
            if ($type === 'dine_in') {
                $payload['table_session_id'] = $session;
            }
            if ($type === 'delivery') {
                $payload['delivery'] = ['address_snapshot' => ['line_one' => 'Main Street']];
            }
            $order = $this->withToken($this->token)->postJson('/api/v1/sales/orders', $payload)->assertCreated();
            $id = $order->json('data.id');
            $added = $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id}/items", [
                'expected_version' => 0, 'variant_id' => $this->variant->id, 'quantity' => '1',
                'client_operation_id' => (string) Str::ulid(),
            ])->assertCreated()->assertJsonMissingPath('data.device_id')->assertJsonMissingPath('data.actor_id');
            $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$id}/place", [
                'expected_version' => $added->json('data.lock_version'), 'client_operation_id' => (string) Str::ulid(),
            ])->assertOk()->assertJsonPath('data.state', 'placed');
        }

        $order = $this->withToken($this->token)->postJson('/api/v1/sales/orders', [
            'type' => 'takeaway', 'currency' => 'IQD', 'client_operation_id' => (string) Str::ulid(),
        ])->json('data.id');
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$order}/tips", [
            'expected_version' => 0, 'amount' => 1.25, 'client_operation_id' => (string) Str::ulid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$order}/items", [
            'expected_version' => 0, 'variant_id' => $this->variant->id, 'quantity' => 1.5,
            'client_operation_id' => (string) Str::ulid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_kds_transitions_and_edge_print_payload_and_attempts_are_safe(): void
    {
        KdsStation::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'device_id' => $this->device->id, 'code' => 'DEFAULT', 'name' => 'Kitchen']);
        $order = $this->withToken($this->token)->postJson('/api/v1/sales/orders', [
            'type' => 'takeaway', 'currency' => 'IQD', 'client_operation_id' => (string) Str::ulid(),
        ])->json('data.id');
        $added = $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$order}/items", [
            'expected_version' => 0, 'variant_id' => $this->variant->id, 'quantity' => '1',
            'client_operation_id' => (string) Str::ulid(),
        ])->json('data.lock_version');
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$order}/place", [
            'expected_version' => $added, 'client_operation_id' => (string) Str::ulid(),
        ])->assertOk();
        $ticket = $this->withToken($this->token)->getJson('/api/v1/sales/kds/tickets')->assertOk()->json('data.0');
        $started = $this->withToken($this->token)->postJson("/api/v1/sales/kds/tickets/{$ticket['id']}/start", [
            'expected_version' => 0, 'client_operation_id' => (string) Str::ulid(),
        ])->assertJsonPath('data.state', 'in_progress');
        $ready = $this->withToken($this->token)->postJson("/api/v1/sales/kds/tickets/{$ticket['id']}/ready", [
            'expected_version' => $started->json('data.lock_version'), 'client_operation_id' => (string) Str::ulid(),
        ])->assertJsonPath('data.state', 'ready');
        $bumped = $this->withToken($this->token)->postJson("/api/v1/sales/kds/tickets/{$ticket['id']}/bump", [
            'expected_version' => $ready->json('data.lock_version'), 'client_operation_id' => (string) Str::ulid(),
        ])->assertJsonPath('data.state', 'bumped');
        $this->withToken($this->token)->postJson("/api/v1/sales/kds/tickets/{$ticket['id']}/recall", [
            'expected_version' => $bumped->json('data.lock_version'), 'client_operation_id' => (string) Str::ulid(),
        ])->assertJsonPath('data.state', 'ready');

        $printer = Printer::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'device_id' => $this->device->id, 'code' => 'EDGE', 'name' => 'Edge', 'connection_type' => 'network',
            'connection_config' => ['host' => 'private-printer'], 'status' => 'active']);
        $job = $this->withToken($this->token)->postJson('/api/v1/sales/printing/jobs', [
            'payload_type' => 'kitchen_ticket', 'document_id' => $ticket['id'], 'printer_id' => $printer->id,
            'idempotency_key' => (string) Str::ulid(), 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonMissingPath('data.payload_hash')->assertJsonMissingPath('data.connection_config');
        $this->assertStringNotContainsString('private-printer', $job->getContent());
        $claimed = $this->withToken($this->token)->postJson('/api/v1/sales/printing/edge/jobs/claim')->assertOk();
        $this->withToken($this->token)->postJson('/api/v1/sales/printing/edge/jobs/'.$claimed->json('data.id').'/complete', [
            'expected_version' => $claimed->json('data.lock_version'),
        ])->assertOk()->assertJsonPath('data.state', 'printed');
        $this->assertDatabaseHas('print_attempts', ['print_job_id' => $claimed->json('data.id'), 'result' => 'printed']);

        $this->withToken($this->token)->postJson('/api/v1/sales/printing/jobs', [
            'payload_type' => 'kitchen_ticket', 'document_id' => $ticket['id'], 'printer_id' => $printer->id,
            'idempotency_key' => (string) Str::ulid(), 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated();
        $failed = $this->withToken($this->token)->postJson('/api/v1/sales/printing/edge/jobs/claim')->assertOk();
        $failed = $this->withToken($this->token)->postJson('/api/v1/sales/printing/edge/jobs/'.$failed->json('data.id').'/fail', [
            'expected_version' => $failed->json('data.lock_version'), 'error_code' => 'PAPER_OUT',
            'error_message' => 'Printer is out of paper.',
        ])->assertOk()->assertJsonPath('data.state', 'failed');
        $this->withToken($this->token)->postJson('/api/v1/sales/printing/edge/jobs/'.$failed->json('data.id').'/retry', [
            'expected_version' => $failed->json('data.lock_version'),
        ])->assertOk()->assertJsonPath('data.state', 'pending');
    }

    public function test_shift_drawer_cash_movement_and_reversal_flow(): void
    {
        $drawer = CashDrawer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'device_id' => $this->device->id,
            'code' => 'MAIN', 'name' => 'Main Drawer',
        ]);
        $shift = $this->withToken($this->token)->postJson('/api/v1/pos/shifts')->assertCreated()->json('data');
        $session = $this->withToken($this->token)->postJson('/api/v1/pos/drawers/sessions', [
            'shift_id' => $shift['id'], 'drawer_id' => $drawer->id, 'currency' => 'IQD', 'opening_amount' => 1000,
        ])->assertCreated()->json('data');
        $movement = $this->withToken($this->token)->postJson("/api/v1/pos/drawers/sessions/{$session['id']}/movements", [
            'type' => 'cash_in', 'amount' => 500, 'currency' => 'IQD',
            'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->json('data');
        $this->withToken($this->token)->postJson("/api/v1/pos/cash-movements/{$movement['id']}/reverse", [
            'reason' => 'Incorrect entry', 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.amount', -500);
        $this->withToken($this->token)->postJson("/api/v1/pos/drawers/sessions/{$session['id']}/close", [
            'counted_amount' => 1000, 'expected_version' => 0,
        ])->assertOk()->assertJsonPath('data.variance_amount', 0);
        $this->withToken($this->token)->postJson("/api/v1/pos/shifts/{$shift['id']}/close", [
            'expected_version' => 0,
        ])->assertOk()->assertJsonPath('data.state', 'closed');
    }

    public function test_customer_mixed_payment_history_and_document_payloads_are_safe(): void
    {
        $customer = $this->withToken($this->token)->postJson('/api/v1/sales/customers', [
            'name' => 'HTTP Customer', 'phone' => '+9647000000000', 'email' => 'customer@example.com',
        ])->assertCreated()->json('data');
        $order = $this->createOrderWithItem('online', '2', ['customer_id' => $customer['id']]);
        $tipped = $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$order['id']}/tips", [
            'expected_version' => $order['lock_version'], 'amount' => 100,
            'client_operation_id' => (string) Str::ulid(),
        ])->assertOk()->json('data');
        $placed = $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$order['id']}/place", [
            'expected_version' => $tipped['lock_version'], 'client_operation_id' => (string) Str::ulid(),
        ])->assertOk()->json('data');
        $card = $this->paymentMethod('card');
        $cash = $this->paymentMethod('cash');
        $first = $this->withToken($this->token)->postJson('/api/v1/sales/billing/payments', [
            'order_id' => $order['id'], 'expected_version' => $placed['lock_version'], 'payment_method_id' => $card->id,
            'amount' => 750, 'idempotency_key' => (string) Str::ulid(), 'client_operation_id' => (string) Str::ulid(),
            'provider_reference' => 'safe-provider-reference', 'provider_snapshot' => ['authorization_code' => 'OK'],
        ])->assertCreated()->assertJsonMissingPath('data.provider_reference')->json('data');
        $this->withToken($this->token)->postJson('/api/v1/sales/billing/payments', [
            'order_id' => $order['id'], 'expected_version' => $placed['lock_version'] + 1, 'payment_method_id' => $cash->id,
            'amount' => 1400, 'idempotency_key' => (string) Str::ulid(), 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.base_amount', 1350);
        $invoice = Invoice::withoutGlobalScopes()->where('order_id', $order['id'])->firstOrFail();
        $receipt = $this->withToken($this->token)->getJson("/api/v1/sales/billing/receipts/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.total_amount', 2100);
        $this->assertStringNotContainsString('safe-provider-reference', $receipt->getContent());
        $this->withToken($this->token)->getJson("/api/v1/sales/customers/{$customer['id']}/history")
            ->assertOk()->assertJsonPath('data.summary.spend_by_currency.IQD', 2100);
        $this->withToken($this->token)->postJson("/api/v1/sales/billing/payments/{$first['id']}/reverse", [
            'amount' => 500, 'reason' => 'Customer refund', 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.amount', 500);

        $printer = $this->printer('DOCS');
        foreach (['receipt', 'invoice', 'qr_verification'] as $type) {
            $job = $this->withToken($this->token)->postJson('/api/v1/sales/printing/jobs', [
                'payload_type' => $type, 'document_id' => $invoice->id, 'printer_id' => $printer->id,
                'idempotency_key' => (string) Str::ulid(), 'client_operation_id' => (string) Str::ulid(),
            ])->assertCreated();
            $this->assertStringNotContainsString('safe-provider-reference', $job->getContent());
        }
        $this->withToken($this->token)->postJson('/api/v1/sales/printing/jobs', [
            'payload_type' => 'barcode_label', 'document_id' => $this->variant->id, 'printer_id' => $printer->id,
            'idempotency_key' => (string) Str::ulid(), 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.payload.document.barcode', $this->variant->barcode);
    }

    public function test_gift_card_split_merge_and_transfer_flows(): void
    {
        $issued = $this->withToken($this->token)->postJson('/api/v1/sales/gift-cards/issue', [
            'currency' => 'IQD', 'initial_amount' => 500, 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated();
        $token = $issued->json('data.token');
        $this->withToken($this->token)->postJson('/api/v1/sales/gift-cards/balance', ['token' => $token])
            ->assertOk()->assertJsonPath('data.balance_amount', 500)->assertJsonMissingPath('data.token');
        $this->withToken($this->token)->postJson('/api/v1/sales/gift-cards/load', [
            'token' => $token, 'currency' => 'IQD', 'amount' => 200, 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.balance_after', 700);

        $giftOrder = $this->createOrderWithItem('takeaway', '1');
        $redemption = $this->withToken($this->token)->postJson('/api/v1/sales/gift-cards/redeem', [
            'token' => $token, 'currency' => 'IQD', 'amount' => 300, 'order_id' => $giftOrder['id'],
            'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.balance_after', 400)->json('data');
        $this->withToken($this->token)->postJson('/api/v1/sales/gift-cards/reverse', [
            'transaction_id' => $redemption['id'], 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.balance_after', 700);

        $source = $this->createOrderWithItem('takeaway', '2');
        $split = $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$source['id']}/split", [
            'expected_version' => $source['lock_version'], 'selections' => [[
                'item_id' => $source['items'][0]['id'], 'quantity' => '1',
            ]], 'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->assertJsonPath('data.total_amount', 1000)->json('data');
        $source = $this->withToken($this->token)->getJson("/api/v1/sales/orders/{$source['id']}")->assertOk()->json('data');
        $merged = $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$split['id']}/merge", [
            'expected_version' => $split['lock_version'], 'source_order_id' => $source['id'],
            'source_version' => $source['lock_version'], 'client_operation_id' => (string) Str::ulid(),
        ])->assertOk()->assertJsonPath('data.total_amount', 2000)->json('data');
        $customer = $this->withToken($this->token)->postJson('/api/v1/sales/customers', [
            'name' => 'Transfer Customer',
        ])->assertCreated()->json('data');
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$merged['id']}/transfer/customer", [
            'expected_version' => $merged['lock_version'], 'customer_id' => $customer['id'],
            'client_operation_id' => (string) Str::ulid(),
        ])->assertOk()->assertJsonPath('data.customer_id', $customer['id']);

        $floor = Floor::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'code' => 'TRANSFER', 'name' => 'Transfer']);
        $firstTable = $this->tableSession($floor, 'A');
        $secondTable = $this->tableSession($floor, 'B');
        $dineIn = $this->createOrderWithItem('dine_in', '1', ['table_session_id' => $firstTable]);
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$dineIn['id']}/transfer/table", [
            'expected_version' => $dineIn['lock_version'], 'target_table_session_id' => $secondTable,
            'client_operation_id' => (string) Str::ulid(),
        ])->assertOk()->assertJsonPath('data.table_session_id', $secondTable);
    }

    private function login(User $user, ?string $device): string
    {
        return $this->postJson('/api/v1/auth/login', ['tenant_slug' => $this->tenant->slug,
            'identifier' => $user->email, 'password' => 'Password123', 'branch_id' => $this->branch->id,
            'device_id' => $device])->assertOk()->json('data.access_token');
    }

    private function catalog(): ProductVariant
    {
        $tax = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'ZERO', 'name' => 'Zero', 'rate_bps' => 0]);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $tax->id,
            'sku' => 'HTTP-ITEM', 'name' => 'HTTP Item']);
        $variant = ProductVariant::factory()->create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id,
            'code' => 'DEFAULT', 'barcode' => '1234567890123']);
        BranchCatalogItem::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'product_variant_id' => $variant->id]);
        $list = PriceList::create(['tenant_id' => $this->tenant->id, 'code' => 'BASE', 'name' => 'Base',
            'currency' => 'IQD', 'channel' => 'all', 'revision' => 1, 'status' => 'published']);
        PriceListItem::create(['tenant_id' => $this->tenant->id, 'price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'tax_class_id' => $tax->id, 'amount' => 1000, 'currency' => 'IQD']);
        $list->publications()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'effective_from' => now()->subDay(), 'priority' => 1]);

        return $variant;
    }

    private function createOrderWithItem(string $type, string $quantity, array $extra = []): array
    {
        $order = $this->withToken($this->token)->postJson('/api/v1/sales/orders', [
            'type' => $type, 'currency' => 'IQD', 'client_operation_id' => (string) Str::ulid(), ...$extra,
        ])->assertCreated()->json('data');

        return $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$order['id']}/items", [
            'expected_version' => $order['lock_version'], 'variant_id' => $this->variant->id, 'quantity' => $quantity,
            'client_operation_id' => (string) Str::ulid(),
        ])->assertCreated()->json('data');
    }

    private function paymentMethod(string $kind): PaymentMethod
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id, 'code' => strtoupper($kind).'-'.Str::random(4),
            'name' => ucfirst($kind), 'kind' => $kind,
        ]);
        BranchPaymentMethod::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'payment_method_id' => $method->id,
        ]);

        return $method;
    }

    private function printer(string $code): Printer
    {
        return Printer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'device_id' => $this->device->id,
            'code' => $code, 'name' => $code, 'connection_type' => 'network',
            'connection_config' => ['host' => 'private-printer'],
        ]);
    }

    private function tableSession(Floor $floor, string $code): string
    {
        $table = DiningTable::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'floor_id' => $floor->id,
            'code' => $code, 'name' => "Table {$code}", 'capacity' => 4,
        ]);

        return $this->withToken($this->token)->postJson('/api/v1/pos/table-sessions', [
            'table_id' => $table->id, 'guest_count' => 2,
        ])->assertCreated()->json('data.id');
    }
}
