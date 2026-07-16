<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Branch;
use App\Models\BranchCatalogItem;
use App\Models\BranchPaymentMethod;
use App\Models\CashDrawer;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\DiscountRule;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Services\CustomerHistoryService;
use App\Modules\Sales\Services\DiscountCouponService;
use App\Modules\Sales\Services\GiftCardService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\PosService;
use App\Modules\Sales\Services\SettlementService;
use App\Modules\Sales\Services\SplitMergeTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesDomainServicesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Branch $otherBranch;

    private User $user;

    private Device $device;

    private SalesContext $context;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'sales-domain', 'name' => 'Sales Domain']);
        $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main']);
        $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'code' => 'OTHER', 'name' => 'Other']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->device = Device::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'POS-1', 'name' => 'POS 1', 'type' => 'pos', 'status' => 'authorized',
            'key_fingerprint' => hash('sha256', 'sales-device'),
        ]);
        $this->context = new SalesContext($this->tenant->id, $this->branch->id, $this->user->id, $this->device->id);
        $this->variant = $this->publishCatalogItem(1000, 'IQD');
    }

    public function test_order_types_lifecycle_stale_scope_and_terminal_invariants(): void
    {
        $orders = app(OrderService::class);
        $order = $this->newOrder($orders, 'takeaway');
        $order = $orders->addItem($this->context, $order->id, 0, $this->variant->id, '2', [], 'pos', 'item-1');
        $this->assertSame(2000, $order->total_amount);

        try {
            $orders->addItem($this->context, $order->id, 0, $this->variant->id, '1', [], 'pos', 'stale');
            $this->fail('Expected stale lock rejection.');
        } catch (SalesException $exception) {
            $this->assertSame(SalesException::STALE_VERSION, $exception->errorCode);
        }
        $otherContext = new SalesContext(
            $this->tenant->id, $this->otherBranch->id, $this->user->id, $this->device->id,
        );
        try {
            $orders->place($otherContext, $order->id, $order->lock_version, 'cross-branch');
            $this->fail('Expected cross-branch order rejection.');
        } catch (SalesException $exception) {
            $this->assertSame(SalesException::NOT_FOUND, $exception->errorCode);
        }

        $order = $orders->place($this->context, $order->id, $order->lock_version, 'place-1');
        $method = $this->paymentMethod('cash');
        $payment = app(SettlementService::class)->capture(
            $this->context, $order->id, $order->lock_version, $method->id,
            2000, 'pay-idem-1', 'pay-op-1',
        );
        $this->assertSame('settled', $payment->order->fresh()->state);
        $this->assertDatabaseHas('invoices', ['order_id' => $order->id, 'total_amount' => 2000]);

        $settled = $order->fresh();
        $this->expectExceptionObject(new SalesException(SalesException::TERMINAL_ORDER, 409, 'Settlement-terminal orders are immutable.'));
        $orders->removeItem($this->context, $settled->id, $settled->items->first()->id, $settled->lock_version, 'terminal');
    }

    public function test_dine_in_shift_drawer_and_table_state_invariants(): void
    {
        $floor = Floor::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'code' => 'GROUND', 'name' => 'Ground',
        ]);
        $table = DiningTable::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'floor_id' => $floor->id, 'code' => 'T1', 'name' => 'Table 1', 'capacity' => 4,
        ]);
        $otherTable = DiningTable::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'floor_id' => $floor->id, 'code' => 'T2', 'name' => 'Table 2', 'capacity' => 4,
        ]);
        $drawer = CashDrawer::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'device_id' => $this->device->id, 'code' => 'D1', 'name' => 'Drawer 1',
        ]);
        $pos = app(PosService::class);
        $shift = $pos->openShift($this->context);
        $session = $pos->openDrawerSession($this->context, $shift->id, $drawer->id, 'IQD', 1000);
        $pos->recordCashMovement($this->context, $session->id, 'cash_in', 250, 'IQD', 'cash-in-1');
        $session = $pos->closeDrawerSession($this->context, $session->id, 1250, $session->lock_version);
        $this->assertSame(0, $session->variance_amount);
        $shift = $pos->closeShift($this->context, $shift->id, $shift->lock_version);
        $this->assertSame('closed', $shift->state);

        $tableSession = $pos->openTableSession($this->context, $table->id, 2);
        $otherSession = $pos->openTableSession($this->context, $otherTable->id, 2);
        $order = $this->newOrder(app(OrderService::class), 'dine_in', ['table_session_id' => $tableSession->id]);
        $this->assertSame($tableSession->id, $order->table_session_id);
        $order = app(SplitMergeTransferService::class)->transferTable(
            $this->context, $order->id, $order->lock_version, $otherSession->id, 'table-transfer',
        );
        $this->assertSame($otherSession->id, $order->table_session_id);
        $this->assertSame('closed', $pos->closeTableSession(
            $this->context, $tableSession->id, $tableSession->lock_version,
        )->state);
        $this->expectException(SalesException::class);
        $pos->closeTableSession($this->context, $otherSession->id, $otherSession->lock_version);
    }

    public function test_split_merge_and_transfer_preserve_exact_totals(): void
    {
        $orders = app(OrderService::class);
        $source = $this->newOrder($orders, 'takeaway');
        $source = $orders->addItem($this->context, $source->id, 0, $this->variant->id, '2', [], 'pos', 'split-item');
        $split = app(SplitMergeTransferService::class)->split(
            $this->context, $source->id, $source->lock_version,
            [['item_id' => $source->items->first()->id, 'quantity' => '1']], 'split-1',
        );
        $this->assertSame(2000, $source->fresh()->total_amount + $split->total_amount);

        $target = $this->newOrder($orders, 'takeaway');
        $target = $orders->addItem($this->context, $target->id, 0, $this->variant->id, '1', [], 'pos', 'merge-a');
        $other = $this->newOrder($orders, 'takeaway');
        $other = $orders->addItem($this->context, $other->id, 0, $this->variant->id, '1', [], 'pos', 'merge-b');
        $merged = app(SplitMergeTransferService::class)->merge(
            $this->context, $target->id, $other->id, $target->lock_version, $other->lock_version, 'merge-1',
        );
        $this->assertSame(2000, $merged->total_amount);
        $this->assertSame('voided', $other->fresh()->state);
        $this->assertDatabaseCount('order_links', 2);
    }

    public function test_coupon_limits_and_gift_card_replay_balance_and_reversal(): void
    {
        $orders = app(OrderService::class);
        $order = $this->newOrder($orders, 'takeaway');
        $order = $orders->addItem($this->context, $order->id, 0, $this->variant->id, '1', [], 'pos', 'coupon-item');
        $discounts = app(DiscountCouponService::class);
        $rule = DiscountRule::create([
            'tenant_id' => $this->tenant->id, 'code' => 'TEN', 'name' => 'Ten Percent',
            'type' => 'percent', 'rate_bps' => 1000, 'currency' => 'IQD', 'status' => 'active',
        ]);
        $token = 'ONCE-ONLY';
        Coupon::create([
            'tenant_id' => $this->tenant->id, 'discount_rule_id' => $rule->id,
            'code_hash' => $discounts->hashToken($token), 'code_last4' => 'ONLY',
            'maximum_redemptions' => 1, 'status' => 'active',
        ]);
        $order = $discounts->redeemCoupon($this->context, $order->id, $order->lock_version, $token, 'coupon-op');
        $this->assertSame(100, $order->discount_amount);
        $this->assertSame($order->id, $discounts->redeemCoupon(
            $this->context, $order->id, $order->lock_version, $token, 'coupon-op',
        )->id);
        $secondOrder = $this->newOrder($orders, 'takeaway');
        $secondOrder = $orders->addItem($this->context, $secondOrder->id, 0, $this->variant->id, '1', [], 'pos', 'coupon-item-2');
        try {
            $discounts->redeemCoupon($this->context, $secondOrder->id, $secondOrder->lock_version, $token, 'coupon-op-2');
            $this->fail('Expected the coupon limit to be enforced under lock.');
        } catch (SalesException $exception) {
            $this->assertSame(SalesException::LIMIT_EXCEEDED, $exception->errorCode);
        }

        $gifts = app(GiftCardService::class);
        $issued = $gifts->issue($this->context, 'IQD', 500, 'gift-issue');
        try {
            $gifts->issue($this->context, 'IQD', 500, 'gift-issue');
            $this->fail('Expected a clear-token replay rejection.');
        } catch (SalesException $exception) {
            $this->assertSame(SalesException::IDEMPOTENCY_CONFLICT, $exception->errorCode);
        }
        $load = $gifts->load($this->context, $issued->token, 200, 'IQD', 'gift-load');
        $this->assertSame($load->id, $gifts->load($this->context, $issued->token, 200, 'IQD', 'gift-load')->id);
        $redeem = $gifts->redeem($this->context, $issued->token, $order->id, 300, 'IQD', 'gift-redeem');
        $this->assertSame(400, $redeem->balance_after);
        $reverse = $gifts->reverse($this->context, $redeem->id, 'gift-reverse');
        $this->assertSame(700, $reverse->balance_after);
    }

    public function test_multiple_payments_issue_invoice_and_customer_history_without_cross_branch_leakage(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        $orders = app(OrderService::class);
        $order = $this->newOrder($orders, 'online', ['customer_id' => $customer->id]);
        $order = $orders->addItem($this->context, $order->id, 0, $this->variant->id, '2', [], 'online', 'history-item');
        $order = $orders->addTip($this->context, $order->id, $order->lock_version, 100, 'history-tip');
        $order = $orders->place($this->context, $order->id, $order->lock_version, 'history-place');
        $card = $this->paymentMethod('card');
        $cash = $this->paymentMethod('cash');
        $settlement = app(SettlementService::class);
        $cardPayment = $settlement->capture($this->context, $order->id, $order->lock_version, $card->id, 750, 'card-idem', 'card-op');
        $order = $order->fresh();
        $settlement->capture($this->context, $order->id, $order->lock_version, $cash->id, 1400, 'cash-idem', 'cash-op');

        $invoice = Invoice::withoutGlobalScopes()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(2100, $invoice->total_amount);
        $this->assertSame(100, $invoice->tip_amount);
        $this->assertSame(2, $invoice->payments()->withoutGlobalScopes()->count());
        $history = app(CustomerHistoryService::class)->history($this->context, $customer->id);
        $this->assertSame(['IQD' => 2100], $history['summary']['spend_by_currency']);

        $otherContext = new SalesContext(
            $this->tenant->id, $this->otherBranch->id, $this->user->id, $this->device->id,
        );
        $this->assertSame(0, app(CustomerHistoryService::class)->history($otherContext, $customer->id)['summary']['order_count']);
        $settlement->reverse($this->context, $cardPayment->id, 500, 'Customer refund', 'reverse-card');
        $this->assertSame('captured', $cardPayment->fresh()->status);
        $this->assertSame(2100, $invoice->fresh()->total_amount);
    }

    /** @param array<string, mixed> $extra */
    private function newOrder(OrderService $orders, string $type, array $extra = []): Order
    {
        return $orders->create($this->context, [
            'type' => $type, 'currency' => 'IQD', 'client_operation_id' => (string) Str::ulid(), ...$extra,
        ]);
    }

    private function publishCatalogItem(int $amount, string $currency): ProductVariant
    {
        $tax = TaxClass::create([
            'tenant_id' => $this->tenant->id, 'code' => 'ZERO', 'name' => 'Zero',
            'rate_bps' => 0, 'status' => 'active',
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id, 'tax_class_id' => $tax->id,
            'sku' => 'SALE-ITEM', 'name' => 'Sale Item',
        ]);
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id, 'product_id' => $product->id,
            'code' => 'DEFAULT', 'barcode' => '9876543210',
        ]);
        BranchCatalogItem::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'product_variant_id' => $variant->id,
        ]);
        $list = PriceList::create([
            'tenant_id' => $this->tenant->id, 'code' => 'BASE', 'name' => 'Base',
            'currency' => $currency, 'channel' => 'all', 'revision' => 1,
            'status' => 'published',
        ]);
        PriceListItem::create([
            'tenant_id' => $this->tenant->id, 'price_list_id' => $list->id,
            'product_variant_id' => $variant->id, 'tax_class_id' => $tax->id,
            'amount' => $amount, 'currency' => $currency,
        ]);
        $list->publications()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'effective_from' => now()->subDay(), 'priority' => 1,
        ]);

        return $variant;
    }

    private function paymentMethod(string $kind): PaymentMethod
    {
        $method = PaymentMethod::create([
            'tenant_id' => $this->tenant->id, 'code' => strtoupper($kind).'-'.Str::random(4),
            'name' => ucfirst($kind), 'kind' => $kind,
        ]);
        BranchPaymentMethod::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'payment_method_id' => $method->id,
        ]);

        return $method;
    }
}
