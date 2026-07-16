<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\BranchPaymentMethod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use App\Models\InvoiceTaxLine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTaxLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\PaymentReversal;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use Illuminate\Support\Facades\DB;

final class SettlementService
{
    private const KINDS = ['cash', 'card', 'wallet', 'gift_card', 'member_account'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly SequenceNumberService $numbers,
        private readonly OrderJournal $journal,
        private readonly GiftCardService $giftCards,
    ) {}

    /** @param array<string, mixed> $providerSnapshot */
    public function capture(
        SalesContext $context,
        string $orderId,
        int $expectedVersion,
        string $paymentMethodId,
        int $amount,
        string $idempotencyKey,
        string $clientOperationId,
        ?string $providerReference = null,
        array $providerSnapshot = [],
        ?string $giftCardToken = null,
    ): Payment {
        if ($amount < 1 || $idempotencyKey === '' || $clientOperationId === '') {
            throw SalesException::invalid('Payment amount, idempotency key, and client operation ID are required.');
        }
        $this->assertSafeProviderSnapshot($providerSnapshot);
        if ($providerReference !== null) {
            $providerPayment = Payment::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('provider_reference', $providerReference)->first();
            if ($providerPayment !== null) {
                if ($providerPayment->order_id === $orderId && $providerPayment->tender_amount === $amount
                    && $providerPayment->payment_method_id === $paymentMethodId) {
                    return $providerPayment;
                }
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The provider reference was reused with a different payment.');
            }
        }
        $existing = Payment::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where(fn ($query) => $query->where('idempotency_key', $idempotencyKey)
                ->orWhere('client_operation_id', $clientOperationId))->first();
        if ($existing !== null) {
            if ($existing->order_id !== $orderId || $existing->tender_amount !== $amount || $existing->payment_method_id !== $paymentMethodId) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The payment reference was reused with a different request.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($context, $orderId, $expectedVersion, $paymentMethodId, $amount, $idempotencyKey, $clientOperationId, $providerReference, $providerSnapshot, $giftCardToken): Payment {
            $order = $this->orders->orderForUpdate($context, $orderId);
            $replayed = Payment::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)
                ->where(fn ($query) => $query->where('idempotency_key', $idempotencyKey)
                    ->orWhere('client_operation_id', $clientOperationId))->first();
            if ($replayed !== null) {
                if ($replayed->order_id !== $orderId || $replayed->tender_amount !== $amount
                    || $replayed->payment_method_id !== $paymentMethodId) {
                    throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The payment reference was reused with a different request.');
                }

                return $replayed;
            }
            $this->orders->assertMutableVersion($order, $expectedVersion);
            if ($order->state === 'draft' || $order->due_amount < 1) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'Only a placed order with an outstanding due amount can accept payment.');
            }
            [$method, $branchMethod] = $this->paymentMethod($context, $paymentMethodId);
            if ($branchMethod->minimum_amount !== null && $amount < $branchMethod->minimum_amount
                || $branchMethod->maximum_amount !== null && $amount > $branchMethod->maximum_amount) {
                throw new SalesException(SalesException::LIMIT_EXCEEDED, 422, 'The payment amount is outside the branch method limits.');
            }
            if ($method->kind !== 'cash' && $amount > $order->due_amount) {
                throw new SalesException(SalesException::PAYMENT_EXCEEDS_DUE, 409, 'Only cash may exceed the exact amount due.');
            }
            $allocated = min($amount, $order->due_amount);
            $snapshot = $providerSnapshot;
            if ($method->kind === 'cash') {
                $snapshot['change_amount'] = $amount - $allocated;
            }
            if ($method->kind === 'gift_card') {
                if ($giftCardToken === null) {
                    throw SalesException::invalid('Gift-card token is required for gift-card payment.');
                }
                $transaction = $this->giftCards->redeem(
                    $context, $giftCardToken, $order->id, $allocated, $order->currency, $clientOperationId.':gift',
                );
                $snapshot['gift_card_transaction_id'] = $transaction->id;
            }
            $payment = Payment::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
                'payment_method_id' => $method->id, 'device_id' => $context->deviceId, 'captured_by' => $context->userId,
                'status' => 'captured', 'tender_amount' => $amount, 'tender_currency' => $order->currency,
                'base_amount' => $allocated, 'base_currency' => $order->currency,
                'provider_reference' => $providerReference, 'idempotency_key' => $idempotencyKey,
                'client_operation_id' => $clientOperationId, 'provider_snapshot' => $snapshot,
                'captured_at' => now(), 'occurred_at' => now(),
            ]);
            $allocation = PaymentAllocation::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'payment_id' => $payment->id, 'order_id' => $order->id, 'amount' => $allocated,
                'currency' => $order->currency, 'client_operation_id' => $clientOperationId,
                'occurred_at' => now(),
            ]);
            PaymentEvent::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'payment_id' => $payment->id, 'sequence' => 1, 'event_type' => 'PaymentCaptured',
                'provider_status' => 'captured', 'provider_reference' => $providerReference,
                'payload' => ['allocated_amount' => $allocated], 'client_operation_id' => $clientOperationId,
                'occurred_at' => now(),
            ]);
            $order->paid_amount += $allocated;
            $order->due_amount -= $allocated;
            $order->lock_version++;
            if ($order->due_amount === 0) {
                $order->state = 'settled';
                $order->settled_at = now();
            }
            $order->save();
            $this->journal->record($context, $order, 'PaymentCaptured', $clientOperationId.':order', [
                'payment_id' => $payment->id, 'amount' => $allocated, 'due_amount' => $order->due_amount,
            ]);
            if ($order->due_amount === 0) {
                $invoice = $this->issueInvoice($context, $order, $clientOperationId.':invoice');
                InvoicePayment::withoutGlobalScopes()->create([
                    'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                    'invoice_id' => $invoice->id, 'payment_allocation_id' => $allocation->id,
                    'payment_snapshot' => $this->paymentSnapshot($payment),
                    'amount' => $allocated, 'currency' => $order->currency, 'occurred_at' => now(),
                ]);
                $this->attachEarlierPayments($context, $order, $invoice, $allocation->id);
                $this->journal->record($context, $order, 'OrderSettled', $clientOperationId.':settled', ['invoice_id' => $invoice->id]);
                if ($order->customer_id !== null) {
                    Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                        ->whereKey($order->customer_id)->update(['last_order_at' => now()]);
                }
            }

            return $payment->refresh()->load(['allocations', 'events']);
        }, 3);
    }

    public function reverse(
        SalesContext $context,
        string $paymentId,
        int $amount,
        string $reason,
        string $clientOperationId,
        ?int $approvedBy = null,
    ): PaymentReversal {
        $existing = PaymentReversal::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('client_operation_id', $clientOperationId)->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($amount < 1 || trim($reason) === '') {
            throw SalesException::invalid('A positive reversal amount and reason are required.');
        }

        return DB::transaction(function () use ($context, $paymentId, $amount, $reason, $clientOperationId, $approvedBy): PaymentReversal {
            $original = Payment::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->whereKey($paymentId)->lockForUpdate()->first();
            if ($original === null || $original->status !== 'captured' || $original->order_id === null) {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The captured payment was not found.');
            }
            $reversed = (int) PaymentReversal::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('original_payment_id', $original->id)->sum('amount');
            if ($amount > $original->base_amount - $reversed) {
                throw new SalesException(SalesException::LIMIT_EXCEEDED, 409, 'The reversal exceeds the remaining captured amount.');
            }
            $order = $this->orders->orderForUpdate($context, $original->order_id);
            $originalSnapshot = (array) $original->provider_snapshot;
            if (isset($originalSnapshot['gift_card_transaction_id'])) {
                if ($amount !== $original->base_amount) {
                    throw SalesException::conflict(SalesException::INVALID_STATE, 'Gift-card payments must be reversed in full.');
                }
            }
            $reversalPayment = Payment::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
                'payment_method_id' => $original->payment_method_id, 'device_id' => $context->deviceId,
                'captured_by' => $context->userId, 'status' => 'reversal',
                'tender_amount' => -$amount, 'tender_currency' => $original->tender_currency,
                'base_amount' => -$amount, 'base_currency' => $original->base_currency,
                'idempotency_key' => $clientOperationId.':payment', 'client_operation_id' => $clientOperationId,
                'provider_snapshot' => ['original_payment_id' => $original->id], 'captured_at' => now(), 'occurred_at' => now(),
            ]);
            PaymentAllocation::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'payment_id' => $reversalPayment->id, 'order_id' => $order->id, 'amount' => -$amount,
                'currency' => $order->currency, 'client_operation_id' => $clientOperationId, 'occurred_at' => now(),
            ]);
            PaymentEvent::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'payment_id' => $reversalPayment->id, 'sequence' => 1, 'event_type' => 'PaymentReversed',
                'payload' => ['original_payment_id' => $original->id, 'reason' => $reason],
                'client_operation_id' => $clientOperationId, 'occurred_at' => now(),
            ]);
            $reversal = PaymentReversal::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'original_payment_id' => $original->id, 'reversal_payment_id' => $reversalPayment->id,
                'amount' => $amount, 'currency' => $order->currency, 'reason' => $reason,
                'actor_id' => $context->userId, 'approved_by' => $approvedBy,
                'client_operation_id' => $clientOperationId, 'occurred_at' => now(),
            ]);
            $giftTransactionId = ((array) $original->provider_snapshot)['gift_card_transaction_id'] ?? null;
            if (is_string($giftTransactionId)) {
                $this->giftCards->reverse($context, $giftTransactionId, $clientOperationId.':gift');
            }
            $order->fill([
                'paid_amount' => $order->paid_amount - $amount,
                'due_amount' => $order->due_amount + $amount,
                'state' => 'payment_reversed', 'lock_version' => $order->lock_version + 1,
            ])->save();
            $this->journal->record($context, $order, 'PaymentReversed', $clientOperationId.':order', [
                'original_payment_id' => $original->id, 'amount' => $amount,
            ]);

            return $reversal;
        }, 3);
    }

    /** @return array{PaymentMethod, BranchPaymentMethod} */
    private function paymentMethod(SalesContext $context, string $id): array
    {
        $method = PaymentMethod::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->where('status', 'active')->where('is_enabled', true)->first();
        $branch = BranchPaymentMethod::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('payment_method_id', $id)
            ->where('is_enabled', true)->lockForUpdate()->first();
        if ($method === null || $branch === null || ! in_array($method->kind, self::KINDS, true)) {
            throw new SalesException(SalesException::NOT_FOUND, 404, 'The payment method is not enabled for this branch.');
        }

        return [$method, $branch];
    }

    private function issueInvoice(SalesContext $context, Order $order, string $operation): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)->first();
        if ($invoice !== null) {
            return $invoice;
        }
        $invoice = Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'order_id' => $order->id,
            'document_type' => 'invoice', 'number' => $this->numbers->nextNumber($context, 'invoice', $order->business_date),
            'business_date' => $order->business_date, 'customer_snapshot' => $order->customer_snapshot,
            'currency' => $order->currency, 'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount, 'charge_amount' => $order->charge_amount,
            'tax_amount' => $order->tax_amount, 'tip_amount' => $order->tip_amount,
            'rounding_amount' => $order->rounding_amount, 'total_amount' => $order->total_amount,
            'policy_revision' => $order->lock_version, 'status' => 'issued',
            'issued_by' => $context->userId, 'issued_at' => now(), 'client_operation_id' => $operation,
        ]);
        $lineIds = [];
        $items = OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)->where('state', 'active')
            ->orderBy('line_number')->get();
        foreach ($items as $item) {
            $line = InvoiceLine::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'invoice_id' => $invoice->id,
                'order_item_id' => $item->id, 'line_number' => $item->line_number,
                'description' => trim($item->product_name.' '.($item->variant_name ?? '')),
                'sku' => $item->sku, 'catalog_snapshot' => $item->catalog_snapshot, 'quantity' => $item->quantity,
                'unit_price_amount' => $item->unit_price_amount, 'gross_amount' => $item->gross_amount,
                'discount_amount' => $item->discount_amount, 'net_amount' => $item->net_amount,
                'currency' => $item->currency,
            ]);
            $lineIds[$item->id] = $line->id;
        }
        $revision = OrderTaxLine::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)->max('calculation_revision');
        $taxes = OrderTaxLine::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)
            ->where('calculation_revision', $revision)->get();
        foreach ($taxes as $tax) {
            InvoiceTaxLine::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'invoice_id' => $invoice->id,
                'invoice_line_id' => $tax->order_item_id === null ? null : ($lineIds[$tax->order_item_id] ?? null),
                'tax_rule_code' => $tax->tax_class_code, 'tax_rule_revision' => $tax->policy_revision,
                'taxable_amount' => $tax->taxable_amount, 'rate_bps' => $tax->rate_bps,
                'tax_amount' => $tax->tax_amount, 'is_inclusive' => $tax->is_inclusive,
                'calculation_order' => $tax->calculation_order, 'currency' => $tax->currency,
            ]);
        }

        return $invoice;
    }

    private function attachEarlierPayments(SalesContext $context, Order $order, Invoice $invoice, string $exceptAllocationId): void
    {
        $allocations = PaymentAllocation::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->where('order_id', $order->id)
            ->where('amount', '>', 0)->whereKeyNot($exceptAllocationId)->get();
        foreach ($allocations as $allocation) {
            InvoicePayment::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'invoice_id' => $invoice->id, 'payment_allocation_id' => $allocation->id,
                'payment_snapshot' => $this->paymentSnapshot($allocation->payment),
                'amount' => $allocation->amount, 'currency' => $allocation->currency, 'occurred_at' => $allocation->occurred_at,
            ]);
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function assertSafeProviderSnapshot(array $snapshot): void
    {
        array_walk_recursive($snapshot, function (mixed $value, mixed $key): void {
            $key = strtolower((string) $key);
            if (in_array($key, ['pan', 'cvv', 'cvc', 'card_number', 'cardnumber'], true)) {
                throw SalesException::invalid('PAN and card verification data must never be accepted or stored.');
            }
        });
    }

    /** @return array<string, mixed> */
    private function paymentSnapshot(Payment $payment): array
    {
        $method = PaymentMethod::withoutGlobalScopes()->find($payment->payment_method_id);

        return [
            'payment_id' => $payment->id, 'method_code' => $method?->code,
            'method_name' => $method?->name, 'kind' => $method?->kind,
            'status' => $payment->status, 'provider_reference' => $payment->provider_reference,
            'captured_at' => $payment->captured_at?->toISOString(),
        ];
    }
}
