<?php

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\BranchCatalogItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Support\Context\AppContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class SalesPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_migrations_create_each_sales_domain_group(): void
    {
        foreach (['products', 'customers', 'orders', 'payments', 'kds_tickets', 'print_jobs', 'sync_outbox_operations'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_catalog_order_and_payment_relations_and_exact_casts(): void
    {
        $tenant = Tenant::create(['slug' => 'sales', 'name' => 'Sales']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id]);
        BranchCatalogItem::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'product_variant_id' => $variant->id,
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'subtotal_amount' => 12500,
            'total_amount' => 12500,
            'due_amount' => 12500,
        ]);
        $item = OrderItem::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'line_number' => 1,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => '2.500000',
            'unit_price_amount' => 5000,
            'gross_amount' => 12500,
            'net_amount' => 12500,
            'currency' => 'IQD',
        ]);
        $payment = Payment::factory()->forOrder($order)->create(['tender_amount' => 12500, 'base_amount' => 12500]);

        $this->assertTrue($product->category->is($category));
        $this->assertTrue($variant->product->is($product));
        $this->assertTrue($order->items->first()->is($item));
        $this->assertTrue($payment->order->is($order));
        $this->assertSame('2.500000', $item->quantity);
        $this->assertSame(12500, $order->total_amount);
        $this->assertSame(12500, $payment->tender_amount);
    }

    public function test_barcodes_are_unique_within_a_tenant(): void
    {
        $tenant = Tenant::create(['slug' => 'barcode', 'name' => 'Barcode']);
        $product = Product::factory()->create(['tenant_id' => $tenant->id]);
        ProductVariant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'barcode' => '1234567890123']);

        $this->expectException(QueryException::class);
        ProductVariant::factory()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'barcode' => '1234567890123']);
    }

    public function test_branch_scope_isolates_orders(): void
    {
        $tenant = Tenant::create(['slug' => 'scope', 'name' => 'Scope']);
        $branchA = Branch::create(['tenant_id' => $tenant->id, 'code' => 'A', 'name' => 'A']);
        $branchB = Branch::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'B']);
        Order::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchA->id]);
        Order::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id]);

        app(AppContext::class)->setTenantId($tenant->id)->setBranchId($branchA->id);

        $this->assertSame(1, Order::count());
        $this->assertSame($branchA->id, Order::first()->branch_id);
    }

    public function test_financial_and_event_rows_are_immutable(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::factory()->forOrder($order)->create();
        $event = OrderEvent::create([
            'tenant_id' => $order->tenant_id,
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'sequence' => 1,
            'event_type' => 'OrderPlaced',
            'client_operation_id' => (string) Str::ulid(),
            'occurred_at' => now(),
        ]);

        try {
            $payment->update(['status' => 'changed']);
            $this->fail('Payment update was not rejected.');
        } catch (LogicException) {
            $this->assertSame('captured', $payment->fresh()->status);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_sales_models_generate_ulid_primary_keys(): void
    {
        $category = Category::factory()->create();
        $order = Order::factory()->create(['tenant_id' => $category->tenant_id]);
        $payment = Payment::factory()->forOrder($order)->create();

        $this->assertTrue(Str::isUlid($category->id));
        $this->assertTrue(Str::isUlid($order->id));
        $this->assertTrue(Str::isUlid($payment->id));
        $this->assertLessThanOrEqual(0, strcmp($category->id, $order->id));
    }

    public function test_money_columns_never_use_floating_point_storage(): void
    {
        $tables = DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'");

        foreach ($tables as $table) {
            $name = str_replace('"', '""', $table->name);
            foreach (DB::select("pragma table_info(\"{$name}\")") as $column) {
                if (! preg_match('/(amount|total|balance|price|fee)$/', $column->name)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/float|double|real/i',
                    $column->type,
                    "{$table->name}.{$column->name} uses {$column->type}",
                );
            }
        }
    }
}
