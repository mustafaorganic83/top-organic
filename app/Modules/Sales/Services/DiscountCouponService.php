<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerMembership;
use App\Models\DiscountRule;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class DiscountCouponService
{
    public function __construct(private readonly OrderService $orders) {}

    public function applyManualFixed(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        int $amount,
        string $reason,
        string $clientOperationId,
        ?int $approvedBy = null,
    ): Order {
        return $this->orders->appendDiscount($context, $orderId, $expectedVersion, [
            'type' => 'fixed', 'name' => 'Manual fixed discount', 'value_amount' => $amount,
            'reason' => $reason, 'approved_by' => $approvedBy,
        ], $clientOperationId);
    }

    public function applyManualPercent(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        int $rateBps,
        string $reason,
        string $clientOperationId,
        ?int $approvedBy = null,
        ?int $maximumAmount = null,
    ): Order {
        return $this->orders->appendDiscount($context, $orderId, $expectedVersion, [
            'type' => 'percent', 'name' => 'Manual percentage discount', 'rate_bps' => $rateBps,
            'maximum_discount_amount' => $maximumAmount, 'reason' => $reason, 'approved_by' => $approvedBy,
        ], $clientOperationId);
    }

    public function applyMembership(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        string $membershipId,
        string $clientOperationId,
    ): Order {
        $order = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($orderId)->first();
        $membership = CustomerMembership::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($membershipId)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        $tier = $membership === null ? null : MembershipTier::withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)->whereKey($membership->membership_tier_id)->where('status', 'active')->first();
        if ($order === null || $membership === null || $tier === null || $order->customer_id !== $membership->customer_id) {
            throw new SalesException(SalesException::NOT_FOUND, 404, 'No applicable active membership was found for this order customer.');
        }

        return $this->orders->appendDiscount($context, $orderId, $expectedVersion, [
            'type' => 'percent', 'name' => $tier->name.' membership', 'code' => $tier->code,
            'rate_bps' => $tier->discount_rate_bps,
        ], $clientOperationId);
    }

    public function redeemCoupon(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        string $token,
        string $clientOperationId,
    ): Order {
        $existing = CouponRedemption::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('client_operation_id', $clientOperationId)->first();
        if ($existing !== null) {
            if ($existing->order_id !== $orderId) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The coupon operation ID was reused for a different order.');
            }

            return Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->find($existing->order_id)
                ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The redeemed coupon order was not found.');
        }
        $hash = $this->hashToken($token);

        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $hash, $clientOperationId): Order {
            $order = $this->orders->orderForUpdate($context, $orderId);
            $this->orders->assertMutableVersion($order, $expectedVersion);
            $coupon = Coupon::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('code_hash', $hash)->lockForUpdate()->first();
            if ($coupon === null || $coupon->status !== 'active'
                || ($coupon->effective_from !== null && $coupon->effective_from->isFuture())
                || ($coupon->effective_to !== null && ! $coupon->effective_to->isFuture())) {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The coupon is invalid or not currently effective.');
            }
            $replayed = CouponRedemption::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('client_operation_id', $clientOperationId)->first();
            if ($replayed !== null) {
                if ($replayed->order_id !== $orderId || $replayed->coupon_id !== $coupon->id) {
                    throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The coupon operation ID was reused with a different request.');
                }

                return Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                    ->where('branch_id', $context->branchId)->find($replayed->order_id)
                    ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The redeemed coupon order was not found.');
            }
            if ($coupon->maximum_redemptions !== null && $coupon->redemption_count >= $coupon->maximum_redemptions) {
                throw new SalesException(SalesException::LIMIT_EXCEEDED, 409, 'The coupon redemption limit has been reached.');
            }
            if ($coupon->maximum_per_customer !== null) {
                if ($order->customer_id === null) {
                    throw SalesException::invalid('This coupon requires an identified customer.');
                }
                $count = CouponRedemption::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                    ->where('coupon_id', $coupon->id)->where('customer_id', $order->customer_id)->count();
                if ($count >= $coupon->maximum_per_customer) {
                    throw new SalesException(SalesException::LIMIT_EXCEEDED, 409, 'The customer coupon limit has been reached.');
                }
            }
            $rule = DiscountRule::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($coupon->discount_rule_id)->first();
            if ($rule === null || ! in_array($rule->status, ['active', 'published'], true)
                || ($rule->effective_from !== null && $rule->effective_from->isFuture())
                || ($rule->effective_to !== null && ! $rule->effective_to->isFuture())) {
                throw new SalesException(SalesException::INVALID_STATE, 409, 'The coupon discount rule is not currently effective.');
            }
            if ($rule->currency !== null && $rule->currency !== $order->currency) {
                throw new SalesException(SalesException::CURRENCY_MISMATCH, 422, 'The coupon currency differs from the order currency.');
            }
            $basis = max(0, $order->subtotal_amount - $order->discount_amount);
            if ($rule->minimum_order_amount !== null && $basis < $rule->minimum_order_amount) {
                throw new SalesException(SalesException::LIMIT_EXCEEDED, 409, 'The order does not meet the coupon minimum.');
            }
            $amount = $rule->type === 'fixed'
                ? (int) $rule->fixed_amount
                : BigInteger::of($basis)->multipliedBy((int) $rule->rate_bps)->dividedBy(10_000, RoundingMode::HalfUp)->toInt();
            $amount = min($basis, $amount, $rule->maximum_discount_amount ?? PHP_INT_MAX);
            $redemption = CouponRedemption::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'coupon_id' => $coupon->id,
                'customer_id' => $order->customer_id, 'order_id' => $order->id,
                'client_operation_id' => $clientOperationId, 'discount_amount' => $amount,
                'currency' => $order->currency, 'redeemed_at' => now(),
            ]);
            $coupon->fill(['redemption_count' => $coupon->redemption_count + 1, 'lock_version' => $coupon->lock_version + 1])->save();
            $discount = [
                'discount_rule_id' => $rule->id, 'coupon_redemption_id' => $redemption->id,
                'code' => $rule->code, 'name' => $rule->name, 'type' => $rule->type,
                'rate_bps' => $rule->rate_bps, 'value_amount' => $rule->fixed_amount,
                'maximum_discount_amount' => $rule->maximum_discount_amount,
            ];

            return $this->orders->appendDiscount($context, $orderId, $expectedVersion, $discount, $clientOperationId);
        }, 3);
    }

    public function hashToken(string $token): string
    {
        $token = mb_strtoupper(trim($token));
        if ($token === '') {
            throw SalesException::invalid('Coupon token is required.');
        }

        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
