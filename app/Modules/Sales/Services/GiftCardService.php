<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Order;
use App\Modules\Sales\Data\IssuedGiftCard;
use App\Modules\Sales\Data\SalesContext;
use App\Modules\Sales\Exceptions\SalesException;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class GiftCardService
{
    public function issue(
        SalesContext $context,
        string $currency,
        int $initialAmount,
        string $clientOperationId,
        ?string $customerId = null,
        ?DateTimeInterface $expiresAt = null,
    ): IssuedGiftCard {
        $this->assertAmountCurrency($initialAmount, $currency, true);
        if (GiftCardTransaction::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('client_operation_id', $clientOperationId)->exists()) {
            throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The gift card was already issued; its clear token cannot be returned again.');
        }
        if ($customerId !== null && ! Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($customerId)->where('status', 'active')->exists()) {
            throw new SalesException(SalesException::NOT_FOUND, 404, 'The gift-card customer was not found.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        return DB::transaction(function () use ($context, $currency, $initialAmount, $clientOperationId, $customerId, $expiresAt, $token): IssuedGiftCard {
            $card = GiftCard::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'customer_id' => $customerId,
                'token_hash' => $this->hashToken($token), 'token_last4' => substr($token, -4),
                'currency' => $currency, 'balance_amount' => $initialAmount, 'status' => 'active',
                'issued_at' => now(), 'expires_at' => $expiresAt,
            ]);
            GiftCardTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId, 'gift_card_id' => $card->id,
                'type' => 'issue', 'amount' => $initialAmount, 'balance_after' => $initialAmount,
                'currency' => $currency, 'client_operation_id' => $clientOperationId,
                'actor_id' => $context->userId, 'occurred_at' => now(),
            ]);

            return new IssuedGiftCard($card, $token);
        }, 3);
    }

    public function load(
        SalesContext $context,
        string $token,
        int $amount,
        string $currency,
        string $clientOperationId,
    ): GiftCardTransaction {
        return $this->mutate($context, $token, $amount, $currency, 'load', $clientOperationId);
    }

    public function redeem(
        SalesContext $context,
        string $token,
        string $orderId,
        int $amount,
        string $currency,
        string $clientOperationId,
    ): GiftCardTransaction {
        $order = Order::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->whereKey($orderId)->first();
        if ($order === null || $order->currency !== $currency || in_array($order->state, ['settled', 'closed', 'cancelled', 'voided'], true)) {
            throw SalesException::conflict(SalesException::INVALID_STATE, 'Gift cards can only be redeemed against a mutable same-currency order in this branch.');
        }

        return $this->mutate($context, $token, $amount, $currency, 'redeem', $clientOperationId, $orderId);
    }

    public function reverse(
        SalesContext $context,
        string $transactionId,
        string $clientOperationId,
    ): GiftCardTransaction {
        $existing = $this->existingOperation($context, $clientOperationId);
        if ($existing !== null) {
            if ($existing->original_transaction_id !== $transactionId) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The gift-card reversal ID was reused for another transaction.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($context, $transactionId, $clientOperationId): GiftCardTransaction {
            $original = GiftCardTransaction::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($transactionId)->lockForUpdate()->first();
            if ($original === null || $original->type === 'reversal') {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The reversible gift-card transaction was not found.');
            }
            if (GiftCardTransaction::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('original_transaction_id', $original->id)->where('type', 'reversal')->exists()) {
                throw SalesException::conflict(SalesException::INVALID_STATE, 'The gift-card transaction has already been reversed.');
            }
            $card = GiftCard::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($original->gift_card_id)->lockForUpdate()->first();
            if ($card === null) {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The gift card was not found.');
            }
            $delta = -$original->amount;
            if ($delta < 0 && $card->balance_amount < abs($delta)) {
                throw new SalesException(SalesException::INSUFFICIENT_BALANCE, 409, 'The gift-card balance cannot cover this reversal.');
            }
            $balance = $this->checkedAdd($card->balance_amount, $delta);
            $card->fill(['balance_amount' => $balance, 'lock_version' => $card->lock_version + 1])->save();

            return GiftCardTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'gift_card_id' => $card->id, 'order_id' => $original->order_id,
                'original_transaction_id' => $original->id, 'type' => 'reversal',
                'amount' => $delta, 'balance_after' => $balance, 'currency' => $card->currency,
                'client_operation_id' => $clientOperationId, 'actor_id' => $context->userId, 'occurred_at' => now(),
            ]);
        }, 3);
    }

    private function mutate(
        SalesContext $context,
        string $token,
        int $amount,
        string $currency,
        string $type,
        string $clientOperationId,
        ?string $orderId = null,
    ): GiftCardTransaction {
        $this->assertAmountCurrency($amount, $currency);
        $existing = $this->existingOperation($context, $clientOperationId);
        if ($existing !== null) {
            $cardId = GiftCard::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('token_hash', $this->hashToken($token))->value('id');
            if ($existing->type !== $type || abs($existing->amount) !== $amount
                || $existing->currency !== $currency || $existing->gift_card_id !== $cardId
                || $existing->order_id !== $orderId) {
                throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The gift-card operation ID was reused with a different request.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($context, $token, $amount, $currency, $type, $clientOperationId, $orderId): GiftCardTransaction {
            $card = GiftCard::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('token_hash', $this->hashToken($token))->lockForUpdate()->first();
            if ($card === null) {
                throw new SalesException(SalesException::NOT_FOUND, 404, 'The gift card was not found.');
            }
            $replayed = $this->existingOperation($context, $clientOperationId);
            if ($replayed !== null) {
                if ($replayed->type !== $type || abs($replayed->amount) !== $amount || $replayed->currency !== $currency) {
                    throw SalesException::conflict(SalesException::IDEMPOTENCY_CONFLICT, 'The gift-card operation ID was reused with a different request.');
                }

                return $replayed;
            }
            $this->assertUsable($card, $currency);
            $delta = $type === 'redeem' ? -$amount : $amount;
            if ($delta < 0 && $card->balance_amount < $amount) {
                throw new SalesException(SalesException::INSUFFICIENT_BALANCE, 409, 'The gift card has insufficient balance.');
            }
            $balance = $this->checkedAdd($card->balance_amount, $delta);
            $card->fill(['balance_amount' => $balance, 'lock_version' => $card->lock_version + 1])->save();

            return GiftCardTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId, 'branch_id' => $context->branchId,
                'gift_card_id' => $card->id, 'order_id' => $orderId, 'type' => $type,
                'amount' => $delta, 'balance_after' => $balance, 'currency' => $currency,
                'client_operation_id' => $clientOperationId, 'actor_id' => $context->userId, 'occurred_at' => now(),
            ]);
        }, 3);
    }

    private function existingOperation(SalesContext $context, string $operation): ?GiftCardTransaction
    {
        return GiftCardTransaction::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('client_operation_id', $operation)->first();
    }

    private function assertUsable(GiftCard $card, string $currency): void
    {
        if ($card->status !== 'active' || ($card->expires_at !== null && ! $card->expires_at->isFuture())) {
            throw SalesException::conflict(SalesException::INVALID_STATE, 'The gift card is inactive or expired.');
        }
        if ($card->currency !== $currency) {
            throw new SalesException(SalesException::CURRENCY_MISMATCH, 422, 'The gift-card currency does not match.');
        }
    }

    private function assertAmountCurrency(int $amount, string $currency, bool $allowZero = false): void
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency) || ($allowZero ? $amount < 0 : $amount < 1)) {
            throw SalesException::invalid('A valid currency and positive integer minor-unit amount are required.');
        }
    }

    private function hashToken(string $token): string
    {
        if (trim($token) === '') {
            throw SalesException::invalid('Gift-card token is required.');
        }

        return hash_hmac('sha256', trim($token), (string) config('app.key'));
    }

    private function checkedAdd(int $balance, int $delta): int
    {
        if (($delta > 0 && $delta > PHP_INT_MAX - $balance) || $balance + $delta < 0) {
            throw new SalesException(SalesException::ARITHMETIC_OVERFLOW, 422, 'The gift-card balance exceeded the supported range.');
        }

        return $balance + $delta;
    }
}
