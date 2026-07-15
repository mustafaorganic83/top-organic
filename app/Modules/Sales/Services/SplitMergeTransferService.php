<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderLink;
use App\Models\TableSession;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class SplitMergeTransferService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly SalesCalculator $calculator,
        private readonly OrderJournal $journal,
    ) {}

    /** @param array<int, array{item_id: string, quantity?: string}> $selections */
    public function split(
        SalesContext $context,
        string $sourceOrderId,
        int $expectedVersion,
        array $selections,
        string $clientOperationId,
    ): Order {
        if ($selections === []) {
            throw SalesException::invalid('At least one order line must be selected for a split.');
        }

        return DB::transaction(function () use ($context, $sourceOrderId, $expectedVersion, $selections, $clientOperationId): Order {
            $source = $this->orders->orderForUpdate($context, $sourceOrderId);
            $this->orders->assertMutableVersion($source, $expectedVersion);
            if ($source->paid_amount > 0) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Partially paid orders cannot be split.');
            }
            $originalTotal = $source->total_amount;
            $selected = [];
            $selectedGross = 0;
            foreach ($selections as $selection) {
                $item = OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                    ->where('branch_id', $context->branchId)->where('order_id', $source->id)
                    ->whereKey($selection['item_id'])->where('state', 'active')->lockForUpdate()->first();
                if ($item === null) {
                    throw new SalesException(SalesException::NOT_FOUND, 404, 'A selected active order line was not found.');
                }
                $available = $this->calculator->quantityToScale($item->quantity);
                $quantity = isset($selection['quantity'])
                    ? $this->calculator->quantityToScale($selection['quantity']) : $available;
                if ($quantity > $available) {
                    throw SalesException::invalid('A split quantity exceeds the source line quantity.');
                }
                $portion = BigInteger::of($item->gross_amount)->multipliedBy($quantity)
                    ->dividedBy($available, RoundingMode::HalfUp)->toInt();
                $selectedGross += $portion;
                $selected[] = [$item, $quantity, $available];
            }
            $target = $this->orders->create($context, [
                'type' => $source->type, 'currency' => $source->currency,
                'table_session_id' => $source->table_session_id, 'pos_shift_id' => $source->pos_shift_id,
                'customer_id' => $source->customer_id, 'source' => 'split', 'source_reference' => $source->number,
                'client_operation_id' => $clientOperationId.':new',
            ]);
            $target = $this->orders->orderForUpdate($context, $target->id);
            [$sourcePolicy, $targetPolicy] = $this->splitPolicy(
                $source->policy_snapshot ?? [], $selectedGross, max(1, $source->subtotal_amount),
            );
            $source->policy_snapshot = $sourcePolicy;
            $source->save();
            $target->policy_snapshot = $targetPolicy;
            $target->save();
            foreach ($selected as [$item, $quantity, $available]) {
                $newItem = $this->copyItem($context, $item, $target);
                $newItem->quantity = $this->calculator->canonicalQuantity($quantity);
                $newItem->save();
                $this->copyModifiers($context, $item, $newItem);
                if ($quantity === $available) {
                    $item->fill(['state' => 'split', 'lock_version' => $item->lock_version + 1])->save();
                } else {
                    $item->fill([
                        'quantity' => $this->calculator->canonicalQuantity($available - $quantity),
                        'lock_version' => $item->lock_version + 1,
                    ])->save();
                }
            }
            $this->orders->recalculateLocked($context, $source);
            $this->orders->recalculateLocked($context, $target);
            $this->reconcileCombinedTotal($source, $target, $originalTotal);
            $this->link($context, $source, $target, 'split');
            $this->journal->record($context, $source, 'OrderSplitSource', $clientOperationId.':source', ['target_order_id' => $target->id]);
            $this->journal->record($context, $target, 'OrderSplitTarget', $clientOperationId.':target', ['source_order_id' => $source->id]);

            return $target->refresh()->load(['items.modifiers']);
        }, 3);
    }

    public function merge(
        SalesContext $context,
        string $targetOrderId,
        string $sourceOrderId,
        int $targetVersion,
        int $sourceVersion,
        string $clientOperationId,
    ): Order {
        if ($targetOrderId === $sourceOrderId) {
            throw SalesException::invalid('An order cannot be merged into itself.');
        }

        return DB::transaction(function () use ($context, $targetOrderId, $sourceOrderId, $targetVersion, $sourceVersion, $clientOperationId): Order {
            $ids = [$targetOrderId, $sourceOrderId];
            sort($ids, SORT_STRING);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = $this->orders->orderForUpdate($context, $id);
            }
            $target = $locked[$targetOrderId];
            $source = $locked[$sourceOrderId];
            $this->orders->assertMutableVersion($target, $targetVersion);
            $this->orders->assertMutableVersion($source, $sourceVersion);
            if ($target->currency !== $source->currency || $target->paid_amount > 0 || $source->paid_amount > 0) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Merge requires same-branch, same-currency, unpaid, non-settled orders.');
            }
            $expectedTotal = $target->total_amount + $source->total_amount;
            $nextLine = (int) OrderItem::withoutGlobalScopes()->where('order_id', $target->id)->max('line_number') + 1;
            $items = OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('order_id', $source->id)->where('state', 'active')
                ->lockForUpdate()->orderBy('line_number')->get();
            foreach ($items as $item) {
                $item->fill(['order_id' => $target->id, 'line_number' => $nextLine++, 'lock_version' => $item->lock_version + 1])->save();
            }
            $targetPolicy = $target->policy_snapshot ?? [];
            $sourcePolicy = $source->policy_snapshot ?? [];
            $targetPolicy['discounts'] = [...($targetPolicy['discounts'] ?? []), ...($sourcePolicy['discounts'] ?? [])];
            $targetPolicy['charges'] = [...($targetPolicy['charges'] ?? []), ...($sourcePolicy['charges'] ?? [])];
            $target->policy_snapshot = $targetPolicy;
            $target->save();
            $this->orders->recalculateLocked($context, $target);
            $delta = $expectedTotal - $target->total_amount;
            $target->fill([
                'rounding_amount' => $target->rounding_amount + $delta,
                'total_amount' => $expectedTotal, 'due_amount' => $expectedTotal,
            ])->save();
            $source->fill([
                'state' => 'voided', 'subtotal_amount' => 0, 'discount_amount' => 0, 'charge_amount' => 0,
                'tax_amount' => 0, 'tip_amount' => 0, 'rounding_amount' => 0,
                'total_amount' => 0, 'due_amount' => 0, 'lock_version' => $source->lock_version + 1,
            ])->save();
            $this->link($context, $source, $target, 'merge');
            $this->journal->record($context, $source, 'OrderMergedSource', $clientOperationId.':source', ['target_order_id' => $target->id]);
            $this->journal->record($context, $target, 'OrderMergedTarget', $clientOperationId.':target', ['source_order_id' => $source->id]);

            return $target->refresh()->load(['items.modifiers']);
        }, 3);
    }

    public function transferTable(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        string $targetTableSessionId,
        string $clientOperationId,
    ): Order {
        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $targetTableSessionId, $clientOperationId): Order {
            $order = $this->orders->orderForUpdate($context, $orderId);
            $this->orders->assertMutableVersion($order, $expectedVersion);
            $session = TableSession::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($targetTableSessionId)
                ->where('state', 'open')->lockForUpdate()->first();
            if ($order->type !== 'dine_in' || $session === null) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only dine-in orders can transfer to an active table session in the same branch.');
            }
            $from = $order->table_session_id;
            $order->fill(['table_session_id' => $session->id, 'lock_version' => $order->lock_version + 1])->save();
            $this->journal->record($context, $order, 'OrderTableTransferred', $clientOperationId, [
                'from_table_session_id' => $from, 'to_table_session_id' => $session->id,
            ]);

            return $order->refresh();
        }, 3);
    }

    public function transferCustomer(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        ?string $customerId,
        string $clientOperationId,
    ): Order {
        return $this->orders->setCustomer($context, $orderId, $expectedVersion, $customerId, $clientOperationId);
    }

    private function copyItem(SalesContext $context, OrderItem $item, Order $target): OrderItem
    {
        $attributes = $item->only([
            'parent_item_id', 'product_id', 'product_variant_id', 'product_name', 'variant_name', 'sku', 'barcode',
            'catalog_snapshot', 'quantity', 'unit_price_amount', 'gross_amount', 'discount_amount', 'tax_amount',
            'net_amount', 'currency', 'tax_class_code', 'state', 'course_number', 'seat_number', 'notes',
        ]);
        $attributes += [
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $target->id,
            'line_number' => (int) OrderItem::withoutGlobalScopes()->where('order_id', $target->id)->max('line_number') + 1,
        ];

        return OrderItem::withoutGlobalScopes()->create($attributes);
    }

    private function copyModifiers(SalesContext $context, OrderItem $source, OrderItem $target): void
    {
        $modifiers = OrderItemModifier::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_item_id', $source->id)->get();
        foreach ($modifiers as $modifier) {
            OrderItemModifier::withoutGlobalScopes()->create([
                ...$modifier->only(['modifier_group_id', 'modifier_option_id', 'line_number', 'group_name', 'option_name',
                    'quantity', 'unit_surcharge_amount', 'total_surcharge_amount', 'currency']),
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_item_id' => $target->id,
            ]);
        }
    }

    /** @param array<string, mixed> $policy @return array{array<string, mixed>, array<string, mixed>} */
    private function splitPolicy(array $policy, int $selectedGross, int $totalGross): array
    {
        $source = $target = $policy;
        $source['discounts'] = $target['discounts'] = [];
        foreach ($policy['discounts'] ?? [] as $discount) {
            if (($discount['type'] ?? null) === 'fixed') {
                $valueKey = array_key_exists('value_amount', $discount) ? 'value_amount' : 'fixed_amount';
                $value = (int) ($discount[$valueKey] ?? 0);
                $part = $this->portion($value, $selectedGross, $totalGross);
                $target['discounts'][] = [...$discount, $valueKey => $part];
                $source['discounts'][] = [...$discount, $valueKey => $value - $part];
            } else {
                $target['discounts'][] = $source['discounts'][] = $discount;
            }
        }
        $source['charges'] = $target['charges'] = [];
        foreach ($policy['charges'] ?? [] as $charge) {
            if (($charge['calculation'] ?? $charge['value_type'] ?? 'fixed') === 'fixed') {
                $key = array_key_exists('fixed_amount', $charge) ? 'fixed_amount' : 'amount';
                $value = (int) ($charge[$key] ?? 0);
                $part = $this->portion($value, $selectedGross, $totalGross);
                $target['charges'][] = [...$charge, $key => $part];
                $source['charges'][] = [...$charge, $key => $value - $part];
            } else {
                $target['charges'][] = $source['charges'][] = $charge;
            }
        }

        return [$source, $target];
    }

    private function portion(int $amount, int $selected, int $total): int
    {
        return BigInteger::of($amount)->multipliedBy($selected)->dividedBy($total, RoundingMode::HalfUp)->toInt();
    }

    private function reconcileCombinedTotal(Order $source, Order $target, int $expected): void
    {
        $actual = $source->total_amount + $target->total_amount;
        $delta = $expected - $actual;
        if ($delta !== 0) {
            $target->fill([
                'rounding_amount' => $target->rounding_amount + $delta,
                'total_amount' => $target->total_amount + $delta,
                'due_amount' => $target->due_amount + $delta,
            ])->save();
        }
    }

    private function link(SalesContext $context, Order $source, Order $target, string $relation): void
    {
        OrderLink::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
            'source_order_id' => $source->id, 'target_order_id' => $target->id,
            'relation' => $relation, 'actor_id' => $context->userId, 'occurred_at' => now(),
        ]);
    }
}
