<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\BranchCatalogItem;
use App\Models\Device;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SyncChangeLogEntry;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncHttpTest extends TestCase
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
        $this->tenant = Tenant::create(['slug' => 'sync-http', 'name' => 'Sync HTTP']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'sync@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($this->user);
        $this->user->assignRole('cashier');
        TenantSecurityPolicy::create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'POS-SYNC', 'name' => 'POS Sync', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'pos-sync-http')]);
        $this->token = $this->login($this->device->id);
        $this->variant = $this->catalog();
    }

    public function test_push_pull_and_cursor_endpoints_enforce_scope_and_stay_non_sensitive(): void
    {
        $orderId = (string) Str::ulid();
        $push = $this->withToken($this->token)->postJson('/api/v1/sales/sync/push', [
            'client_batch_id' => (string) Str::ulid(),
            'operations' => [
                ['client_operation_id' => (string) Str::ulid(), 'entity_type' => 'order', 'entity_id' => $orderId,
                    'command' => 'order.create', 'device_sequence' => 1, 'payload' => ['type' => 'takeaway', 'currency' => 'IQD']],
            ],
        ])->assertOk();
        $push->assertJsonPath('data.results.0.result', 'applied');
        $this->assertStringNotContainsString($this->tenant->id, $push->getContent());

        SyncChangeLogEntry::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'change_sequence' => 2, 'entity_type' => 'order', 'entity_id' => $orderId, 'entity_revision' => 1,
            'operation' => 'upsert', 'manifest' => ['secret' => 'leak'], 'occurred_at' => now()]);
        $pull = $this->withToken($this->token)->getJson('/api/v1/sales/sync/pull?cursor=0&limit=10')->assertOk();
        $pull->assertJsonPath('data.cursor', 2);
        $this->assertStringNotContainsString('leak', $pull->getContent());

        $this->withToken($this->token)->postJson('/api/v1/sales/sync/cursor', ['sequence' => 2])
            ->assertOk()->assertJsonPath('data.last_sequence', 2);
    }

    public function test_push_rejects_scope_fields_in_payload(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/sales/sync/push', [
            'client_batch_id' => (string) Str::ulid(),
            'operations' => [
                ['client_operation_id' => (string) Str::ulid(), 'entity_type' => 'order', 'entity_id' => (string) Str::ulid(),
                    'command' => 'order.create', 'device_sequence' => 1,
                    'payload' => ['type' => 'takeaway', 'currency' => 'IQD', 'tenant_id' => (string) Str::ulid()]],
            ],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_conflict_review_requires_elevated_permission(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/sales/sync/conflicts')->assertOk();
        $waiter = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'waiter@example.com', 'password' => 'Password123']);
        $this->branch->users()->attach($waiter);
        $waiter->assignRole('waiter');
        $this->withToken($this->login($this->device->id, $waiter))
            ->getJson('/api/v1/sales/sync/conflicts')->assertForbidden();
    }

    private function login(?string $device, ?User $user = null): string
    {
        $user ??= $this->user;

        return $this->postJson('/api/v1/auth/login', ['tenant_slug' => $this->tenant->slug,
            'identifier' => $user->email, 'password' => 'Password123', 'branch_id' => $this->branch->id,
            'device_id' => $device])->assertOk()->json('data.access_token');
    }

    private function catalog(): ProductVariant
    {
        $tax = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'ZERO', 'name' => 'Zero', 'rate_bps' => 0]);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $tax->id,
            'sku' => 'SYNC-HTTP', 'name' => 'Sync HTTP']);
        $variant = ProductVariant::factory()->create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id,
            'code' => 'DEFAULT', 'barcode' => '4443332221110']);
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
}
