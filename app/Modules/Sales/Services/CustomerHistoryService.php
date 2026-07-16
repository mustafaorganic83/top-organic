<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;

final class CustomerHistoryService
{
    /** @return array<string, mixed> */
    public function history(SalesContext $context, string $customerId, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw SalesException::invalid('History limit must be between 1 and 200.');
        }
        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($customerId)->first();
        if ($customer === null) {
            throw new SalesException(SalesException::NOT_FOUND, 404, 'The customer was not found in this tenant.');
        }
        $orders = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('customer_id', $customer->id)
            ->latest('created_at')->limit($limit)->get();
        $spend = [];
        $settledCount = 0;
        foreach ($orders->whereIn('state', ['settled', 'closed', 'payment_reversed']) as $order) {
            $spend[$order->currency] = $this->checkedAdd($spend[$order->currency] ?? 0, $order->paid_amount);
            $settledCount++;
        }
        $payments = Payment::withoutGlobalScopes()->where('payments.tenant_id', $context->tenantId)
            ->where('payments.branch_id', $context->branchId)->whereIn('payments.order_id', $orders->pluck('id'))
            ->with('method')->latest('occurred_at')->limit($limit)->get()->map(fn (Payment $payment): array => [
                'id' => $payment->id, 'order_id' => $payment->order_id,
                'kind' => $payment->method?->kind, 'status' => $payment->status,
                'amount' => $payment->base_amount, 'currency' => $payment->base_currency,
                'occurred_at' => $payment->occurred_at,
            ])->all();

        return [
            'customer' => $customer->only(['id', 'customer_number', 'name', 'locale', 'status']),
            'summary' => ['order_count' => $orders->count(), 'settled_order_count' => $settledCount, 'spend_by_currency' => $spend],
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id, 'number' => $order->number, 'type' => $order->type, 'state' => $order->state,
                'currency' => $order->currency, 'total_amount' => $order->total_amount,
                'paid_amount' => $order->paid_amount, 'business_date' => $order->business_date,
                'placed_at' => $order->placed_at, 'settled_at' => $order->settled_at,
            ])->all(),
            'payments' => $payments,
        ];
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (($right > 0 && $right > PHP_INT_MAX - $left) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'Customer spend exceeded the supported range.');
        }

        return $left + $right;
    }
}
