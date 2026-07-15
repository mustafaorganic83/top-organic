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
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Data\SalesContext;
use App\Support\Context\AppContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixture for offline-sync service tests: a trusted tenant/branch/device
 * context and a published catalog item to build orders from.
 */
abstract class SyncTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Branch $branch;

    protected Branch $otherBranch;

    protected User $user;

    protected Device $device;

    protected SalesContext $context;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'sync', 'name' => 'Sync']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'OTHER', 'name' => 'Other']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'POS-SYNC', 'name' => 'POS Sync', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'pos-sync')]);
        $this->context = new SalesContext($this->tenant->id, $this->branch->id, $this->user->id, $this->device->id);
        app(AppContext::class)->setTenantId($this->tenant->id)->setBranchId($this->branch->id)->setDeviceId($this->device->id);
        $this->variant = $this->publishCatalogItem(1000, 'IQD');
    }

    protected function publishCatalogItem(int $amount, string $currency): ProductVariant
    {
        $tax = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'ZERO', 'name' => 'Zero', 'rate_bps' => 0]);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'tax_class_id' => $tax->id,
            'sku' => 'SYNC-ITEM', 'name' => 'Sync Item']);
        $variant = ProductVariant::factory()->create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id,
            'code' => 'DEFAULT', 'barcode' => '5556667778889']);
        BranchCatalogItem::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'product_variant_id' => $variant->id]);
        $list = PriceList::create(['tenant_id' => $this->tenant->id, 'code' => 'BASE', 'name' => 'Base',
            'currency' => $currency, 'channel' => 'all', 'revision' => 1, 'status' => 'published']);
        PriceListItem::create(['tenant_id' => $this->tenant->id, 'price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'tax_class_id' => $tax->id, 'amount' => $amount, 'currency' => $currency]);
        $list->publications()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'effective_from' => now()->subDay(), 'priority' => 1]);

        return $variant;
    }
}
