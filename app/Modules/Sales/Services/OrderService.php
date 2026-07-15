<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Customer;
use App\Models\DeliveryFulfillment;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderTaxLine;
use App\Models\OrderTip;
use App\Models\PosShift;
use App\Models\TableSession;
use App\Modules\Sales\Data\CalculationInput;
use App\Modules\Sales\Data\CatalogSnapshot;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    private const TYPES = ['dine_in', 'takeaway', 'delivery', 'online'];

    private const TERMINAL = ['settled', 'closed', 'cancelled', 'voided'];

    public function __construct(
        private readonly SalesCalculator $calculator,
        private readonly CatalogService $catalog,
        private readonly SequenceNumberService $numbers,
        private readonly OrderJournal $journal,
        private readonly KitchenService $kitchen,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(SalesContext $context, array $data): Order
    {
        $this->assertNoScope($data);
        $type = (string) ($data['type'] ?? '');
        $currency = (string) ($data['currency'] ?? '');
        $operation = (string) ($data['client_operation_id'] ?? '');
        if (! in_array($type, self::TYPES, true) || $operation === '') {
            throw SalesException::invalid('Order type and client operation ID are required.');
        }
        $this->calculator->calculate(new CalculationInput($currency, []));
        $existing = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('client_operation_id', $operation)->first();
        if ($existing !== null) {
            if ($existing->type !== $type || $existing->currency !== $currency
                || $existing->table_session_id !== ($data['table_session_id'] ?? null)) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The order operation ID was reused with a different request.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($context, $data, $type, $currency, $operation): Order {
            $tableSessionId = $data['table_session_id'] ?? null;
            if ($type === 'dine_in') {
                if (! is_string($tableSessionId)) {
                    throw SalesException::invalid('Dine-in orders require an active table session.');
                }
                $this->activeTableSession($context, $tableSessionId);
            } elseif ($tableSessionId !== null) {
                throw SalesException::invalid('Only dine-in orders may reference a table session.');
            }
            $shiftId = $data['pos_shift_id'] ?? null;
            if ($shiftId !== null) {
                $shift = PosShift::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                    ->where('branch_id', $context->branchId)->whereKey($shiftId)->lockForUpdate()->first();
                if ($shift === null || $shift->state !== 'open') {
                    throw SalesException::conflict(SalesException::INVALID_STATE, 'The selected POS shift is not open.');
                }
            }
            [$customerId, $customerSnapshot] = $this->customerSnapshot($context, $data['customer_id'] ?? null);
            $businessDate = now()->toDateString();
            $order = Order::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'device_id' => $context->deviceId, 'pos_shift_id' => $shiftId, 'table_session_id' => $tableSessionId,
                'customer_id' => $customerId, 'number' => $this->numbers->nextNumber($context, 'order', $businessDate),
                'type' => $type, 'source' => $data['source'] ?? 'pos', 'source_reference' => $data['source_reference'] ?? null,
                'state' => 'draft', 'currency' => $currency, 'customer_snapshot' => $customerSnapshot,
                'policy_snapshot' => ['discounts' => [], 'charges' => [], 'rounding_increment' => 1],
                'business_date' => $businessDate, 'client_operation_id' => $operation,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
            if ($type === 'delivery' && isset($data['delivery'])) {
                $this->writeDelivery($context, $order, (array) $data['delivery']);
            }
            $this->journal->record($context, $order, 'OrderCreated', $operation, ['type' => $type]);

            return $order->refresh();
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $modifiers */
    public function addItem(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        string $variantId,
        string $quantity,
        array $modifiers,
        string $channel,
        string $clientOperationId,
    ): Order {
        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $variantId, $quantity, $modifiers, $channel, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            $snapshot = $this->catalog->resolve($context, $variantId, $channel);
            if ($snapshot->currency !== $order->currency) {
                throw new SalesException(SalesException::CURRENCY_MISMATCH, 422, 'The catalog price currency differs from the order currency.');
            }
            $this->calculator->quantityToScale($quantity);
            $line = (int) OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('order_id', $order->id)->max('line_number') + 1;
            $item = OrderItem::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
                'product_id' => $snapshot->productId, 'product_variant_id' => $snapshot->variantId, 'line_number' => $line,
                'product_name' => $snapshot->productName, 'variant_name' => $snapshot->variantName,
                'sku' => $snapshot->sku, 'barcode' => $snapshot->barcode, 'catalog_snapshot' => $snapshot->toArray(),
                'quantity' => $quantity, 'unit_price_amount' => $snapshot->unitPriceAmount,
                'gross_amount' => 0, 'net_amount' => 0, 'currency' => $snapshot->currency,
                'tax_class_code' => $snapshot->taxClassCode, 'state' => 'active',
            ]);
            $this->replaceModifiers($context, $item, $snapshot, $modifiers);
            $this->recalculateLocked($context, $order);
            $this->journal->record($context, $order, 'OrderItemAdded', $clientOperationId, ['order_item_id' => $item->id]);

            return $order->refresh()->load(['items.modifiers']);
        }, 3);
    }

    /** @param array<string, mixed> $changes */
    public function updateItem(
        SalesContext $context,
        string $orderId,
        string $itemId,
        int $expectedVersion,
        array $changes,
        string $clientOperationId,
    ): Order {
        $this->assertNoScope($changes);

        return DB::transaction(function () use ($context, $orderId, $itemId, $expectedVersion, $changes, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            $item = OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('order_id', $orderId)->whereKey($itemId)->lockForUpdate()->first();
            if ($item === null || $item->state !== 'active') {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The active order item was not found.');
            }
            if (isset($changes['quantity'])) {
                $this->calculator->quantityToScale($changes['quantity']);
                $item->quantity = $changes['quantity'];
            }
            $item->fill(collect($changes)->only(['course_number', 'seat_number', 'notes'])->all())->save();
            if (array_key_exists('modifiers', $changes)) {
                $snapshot = $this->snapshotFromItem($item);
                $this->replaceModifiers($context, $item, $snapshot, (array) $changes['modifiers']);
            }
            $this->recalculateLocked($context, $order);
            $this->journal->record($context, $order, 'OrderItemUpdated', $clientOperationId, ['order_item_id' => $item->id]);

            return $order->refresh()->load(['items.modifiers']);
        }, 3);
    }

    public function removeItem(
        SalesContext $context,
        string $orderId,
        string $itemId,
        int $expectedVersion,
        string $clientOperationId,
    ): Order {
        return DB::transaction(function () use ($context, $orderId, $itemId, $expectedVersion, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            $item = OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('order_id', $orderId)->whereKey($itemId)->lockForUpdate()->first();
            if ($item === null || $item->state !== 'active') {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The active order item was not found.');
            }
            $item->fill(['state' => 'removed', 'lock_version' => $item->lock_version + 1])->save();
            $this->recalculateLocked($context, $order);
            $this->journal->record($context, $order, 'OrderItemRemoved', $clientOperationId, ['order_item_id' => $item->id]);

            return $order->refresh()->load(['items.modifiers']);
        }, 3);
    }

    public function setCustomer(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        ?string $customerId,
        string $clientOperationId,
    ): Order {
        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $customerId, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            [$id, $snapshot] = $this->customerSnapshot($context, $customerId);
            $order->fill(['customer_id' => $id, 'customer_snapshot' => $snapshot, 'lock_version' => $order->lock_version + 1])->save();
            $this->journal->record($context, $order, 'OrderCustomerChanged', $clientOperationId, ['customer_id' => $id]);

            return $order->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $delivery */
    public function setDelivery(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        array $delivery,
        string $clientOperationId,
    ): Order {
        $this->assertNoScope($delivery);

        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $delivery, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            if ($order->type !== 'delivery') {
                throw SalesException::invalid('Delivery snapshots are only valid for delivery orders.');
            }
            $this->writeDelivery($context, $order, $delivery);
            $order->increment('lock_version');
            $order->refresh();
            $this->journal->record($context, $order, 'OrderDeliveryChanged', $clientOperationId);

            return $order;
        }, 3);
    }

    public function place(SalesContext $context, string $orderId, int $expectedVersion, string $clientOperationId): Order
    {
        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            if ($order->state !== 'draft' || ! $order->items()->withoutGlobalScopes()->where('state', 'active')->exists()) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only a non-empty draft order can be placed.');
            }
            if ($order->type === 'delivery' && ! $order->delivery()->withoutGlobalScopes()->exists()) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'A delivery snapshot is required before placing the order.');
            }
            $this->recalculateLocked($context, $order);
            $order->fill(['state' => 'placed', 'placed_at' => now(), 'lock_version' => $order->lock_version + 1])->save();
            $this->journal->record($context, $order, 'OrderPlaced', $clientOperationId, ['total_amount' => $order->total_amount]);

            if ($this->kitchen->hasActiveStation($context)) {
                $this->kitchen->dispatch($context, $order->id, $clientOperationId.':kds');
            }

            return $order->refresh();
        }, 3);
    }

    public function transition(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        string $state,
        string $clientOperationId,
    ): Order {
        $allowed = [
            'draft' => ['cancelled'], 'placed' => ['confirmed', 'cancelled'],
            'confirmed' => ['preparing', 'cancelled'], 'preparing' => ['ready', 'cancelled'],
            'ready' => ['completed', 'cancelled'], 'completed' => [],
        ];

        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $state, $clientOperationId, $allowed): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            if (! in_array($state, $allowed[$order->state] ?? [], true)) {
                throw SalesException::conflict(SalesException::INVALID_STATE, "Order cannot transition from {$order->state} to {$state}.");
            }
            $order->fill(['state' => $state, 'lock_version' => $order->lock_version + 1])->save();
            $this->journal->record($context, $order, 'OrderStateChanged', $clientOperationId, ['state' => $state]);

            return $order->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $discount */
    public function appendDiscount(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        array $discount,
        string $clientOperationId,
    ): Order {
        $this->assertNoScope($discount);

        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $discount, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            $previousDiscount = $order->discount_amount;
            $policy = $order->policy_snapshot ?? [];
            $policy['discounts'][] = $discount;
            $order->policy_snapshot = $policy;
            $order->save();
            $this->recalculateLocked($context, $order);
            $result = end($policy['discounts']);
            OrderDiscount::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
                'discount_rule_id' => $result['discount_rule_id'] ?? null,
                'coupon_redemption_id' => $result['coupon_redemption_id'] ?? null,
                'code' => $result['code'] ?? null, 'name' => $result['name'] ?? 'Discount', 'type' => $result['type'],
                'rate_bps' => $result['rate_bps'] ?? null, 'value_amount' => $result['value_amount'] ?? $result['fixed_amount'] ?? null,
                'applied_amount' => $order->discount_amount - $previousDiscount, 'currency' => $order->currency,
                'reason' => $result['reason'] ?? null, 'actor_id' => $context->userId,
                'approved_by' => $result['approved_by'] ?? null, 'occurred_at' => now(),
            ]);
            $this->journal->record($context, $order, 'OrderDiscountApplied', $clientOperationId, ['code' => $result['code'] ?? null]);

            return $order->refresh();
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $charges */
    public function replaceCharges(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        array $charges,
        string $clientOperationId,
    ): Order {
        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $charges, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            $policy = $order->policy_snapshot ?? [];
            $policy['charges'] = $charges;
            $order->policy_snapshot = $policy;
            $order->save();
            $this->recalculateLocked($context, $order);
            $basis = $order->subtotal_amount - $order->discount_amount;
            foreach ($charges as $charge) {
                $applied = ($charge['calculation'] ?? $charge['value_type'] ?? 'fixed') === 'percent'
                    ? BigInteger::of($basis)->multipliedBy((int) $charge['rate_bps'])
                        ->dividedBy(10_000, RoundingMode::HalfUp)->toInt()
                    : (int) ($charge['amount'] ?? $charge['fixed_amount'] ?? 0);
                OrderCharge::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
                    'tax_class_id' => $charge['tax_class_id'] ?? null, 'code' => $charge['code'],
                    'name' => $charge['name'], 'type' => $charge['type'] ?? 'service_charge',
                    'basis_amount' => $basis,
                    'rate_bps' => $charge['rate_bps'] ?? null, 'fixed_amount' => $charge['fixed_amount'] ?? null,
                    'amount' => $applied, 'currency' => $order->currency, 'occurred_at' => now(),
                ]);
            }
            $this->journal->record($context, $order, 'OrderChargesChanged', $clientOperationId);

            return $order->refresh();
        }, 3);
    }

    public function addTip(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        int $amount,
        string $clientOperationId,
    ): Order {
        if ($amount < 0) {
            throw SalesException::invalid('Tip must be a non-negative integer in minor units.');
        }

        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $amount, $clientOperationId): Order {
            $order = $this->orderForUpdate($context, $orderId);
            $this->assertMutableVersion($order, $expectedVersion);
            OrderTip::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'client_operation_id' => $clientOperationId],
                ['order_id' => $order->id, 'amount' => $amount, 'currency' => $order->currency, 'source' => 'customer', 'occurred_at' => now()],
            );
            $this->recalculateLocked($context, $order);
            $this->journal->record($context, $order, 'OrderTipAdded', $clientOperationId, ['amount' => $amount]);

            return $order->refresh();
        }, 3);
    }

    public function recalculateLocked(SalesContext $context, Order $order): Order
    {
        $items = OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)->where('state', 'active')
            ->with(['modifiers' => fn ($query) => $query->withoutGlobalScopes()->orderBy('line_number')])->orderBy('line_number')->get();
        $lines = $items->map(function (OrderItem $item): array {
            $snapshot = $item->catalog_snapshot ?? [];

            return [
                'line_id' => $item->id, 'quantity' => $item->quantity, 'unit_price_amount' => $item->unit_price_amount,
                'currency' => $item->currency, 'tax_class_code' => $item->tax_class_code,
                'tax_rate_bps' => (int) ($snapshot['taxRateBps'] ?? 0), 'tax_inclusive' => (bool) ($snapshot['taxInclusive'] ?? false),
                'modifiers' => $item->modifiers->map(fn (OrderItemModifier $modifier): array => [
                    'quantity' => $modifier->quantity, 'unit_surcharge_amount' => $modifier->unit_surcharge_amount,
                    'currency' => $modifier->currency, 'option_id' => $modifier->modifier_option_id,
                ])->all(),
            ];
        })->all();
        $tip = 0;
        foreach (OrderTip::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)->get() as $tipRow) {
            $tip = $this->checkedAdd($tip, $tipRow->amount);
        }
        $policy = $order->policy_snapshot ?? [];
        $result = $this->calculator->calculate(new CalculationInput(
            $order->currency, $lines, $policy['discounts'] ?? [], $policy['charges'] ?? [],
            $tip, (int) ($policy['rounding_increment'] ?? 1),
        ));
        if ($order->paid_amount > $result->totalAmount) {
            throw SalesException::conflict(SalesException::PAYMENT_EXCEEDS_DUE, 'Order changes cannot reduce the total below captured payments.');
        }
        foreach ($result->lines as $line) {
            OrderItem::withoutGlobalScopes()->whereKey($line['line_id'])->update([
                'gross_amount' => $line['gross_amount'], 'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'], 'net_amount' => $line['net_amount'],
            ]);
            $item = $items->firstWhere('id', $line['line_id']);
            if ($item !== null) {
                foreach ($line['modifiers'] as $position => $modifier) {
                    $storedModifier = $item->modifiers->get($position);
                    $storedModifier?->update(['total_surcharge_amount' => $modifier['total_surcharge_amount']]);
                }
            }
        }
        $revision = $order->lock_version + 1;
        $order->fill([
            'subtotal_amount' => $result->subtotalAmount, 'discount_amount' => $result->discountAmount,
            'charge_amount' => $result->chargeAmount, 'tax_amount' => $result->taxAmount,
            'tip_amount' => $result->tipAmount, 'rounding_amount' => $result->roundingAmount,
            'total_amount' => $result->totalAmount, 'due_amount' => $result->totalAmount - $order->paid_amount,
            'lock_version' => $revision,
        ])->save();
        foreach ($result->taxes as $position => $tax) {
            OrderTaxLine::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
                'order_item_id' => $tax['source'] === 'line' ? $tax['source_id'] : null,
                'calculation_revision' => $revision, 'tax_class_code' => $tax['tax_class_code'] ?? 'TAX',
                'taxable_amount' => $tax['taxable_amount'], 'rate_bps' => $tax['rate_bps'],
                'tax_amount' => $tax['tax_amount'], 'is_inclusive' => $tax['is_inclusive'],
                'calculation_order' => $position, 'currency' => $order->currency,
            ]);
        }

        return $order;
    }

    public function orderForUpdate(SalesContext $context, string $id): Order
    {
        return Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->lockForUpdate()->first()
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The order was not found in this branch.');
    }

    public function assertMutableVersion(Order $order, int $expectedVersion): void
    {
        if (in_array($order->state, self::TERMINAL, true) || $order->settled_at !== null) {
            throw SalesException::conflict(SalesException::TERMINAL_ORDER, 'Settlement-terminal orders are immutable.');
        }
        if ($order->lock_version !== $expectedVersion) {
            throw SalesException::conflict(SalesException::STALE_VERSION, 'The order was changed by another operation.');
        }
    }

    /** @param array<int, array<string, mixed>> $modifiers */
    private function replaceModifiers(SalesContext $context, OrderItem $item, CatalogSnapshot $snapshot, array $modifiers): void
    {
        OrderItemModifier::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_item_id', $item->id)->delete();
        foreach ($modifiers as $index => $selection) {
            $modifier = $this->catalog->resolveModifier($context, $snapshot, (string) ($selection['option_id'] ?? ''));
            $quantity = (string) ($selection['quantity'] ?? '1');
            $this->calculator->quantityToScale($quantity);
            OrderItemModifier::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_item_id' => $item->id,
                'modifier_group_id' => $modifier->groupId, 'modifier_option_id' => $modifier->optionId,
                'line_number' => $index + 1, 'group_name' => $modifier->groupName, 'option_name' => $modifier->optionName,
                'quantity' => $quantity, 'unit_surcharge_amount' => $modifier->unitSurchargeAmount,
                'total_surcharge_amount' => 0, 'currency' => $modifier->currency,
            ]);
        }
    }

    private function snapshotFromItem(OrderItem $item): CatalogSnapshot
    {
        $value = $item->catalog_snapshot;
        if (! is_array($value)) {
            throw SalesException::conflict(SalesException::INVALID_STATE, 'The order item has no immutable catalog snapshot.');
        }

        return new CatalogSnapshot(...$value);
    }

    /** @return array{?string, ?array<string, mixed>} */
    private function customerSnapshot(SalesContext $context, mixed $customerId): array
    {
        if ($customerId === null) {
            return [null, null];
        }
        if (! is_string($customerId)) {
            throw SalesException::invalid('Customer ID must be a string or null.');
        }
        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($customerId)->where('status', 'active')->first();
        if ($customer === null) {
            throw new SalesException(SalesException::NOT_FOUND, 404, 'The active customer was not found in this tenant.');
        }

        return [$customer->id, $customer->only(['id', 'customer_number', 'name', 'phone', 'email', 'locale'])];
    }

    /** @param array<string, mixed> $delivery */
    private function writeDelivery(SalesContext $context, Order $order, array $delivery): void
    {
        $address = $delivery['address_snapshot'] ?? null;
        if (! is_array($address) || $address === []) {
            throw SalesException::invalid('A non-empty delivery address snapshot is required.');
        }
        DeliveryFulfillment::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id],
            ['customer_id' => $order->customer_id, 'customer_address_id' => $delivery['customer_address_id'] ?? null,
                'address_snapshot' => $address, 'contact_snapshot' => $delivery['contact_snapshot'] ?? null,
                'provider' => $delivery['provider'] ?? null, 'provider_reference' => $delivery['provider_reference'] ?? null,
                'state' => 'pending', 'fee_amount' => $delivery['fee_amount'] ?? 0,
                'currency' => $order->currency, 'promised_at' => $delivery['promised_at'] ?? null],
        );
    }

    private function activeTableSession(SalesContext $context, string $id): TableSession
    {
        return TableSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($id)->where('state', 'open')->lockForUpdate()->first()
            ?? throw SalesException::conflict(SalesException::INVALID_STATE, 'Dine-in orders require an active table session in this branch.');
    }

    /** @param array<string, mixed> $data */
    private function assertNoScope(array $data): void
    {
        foreach (['tenant_id', 'branch_id', 'user_id', 'actor_id', 'device_id'] as $key) {
            if (array_key_exists($key, $data)) {
                throw new SalesException(SalesException::SCOPE_VIOLATION, 403, 'Scope and actor IDs must come from the trusted sales context.');
            }
        }
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'The monetary total exceeded the supported range.');
        }

        return $left + $right;
    }
}
