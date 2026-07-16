<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\GiftCardRequest;
use App\Modules\Sales\Services\GiftCardService;
use Illuminate\Http\JsonResponse;

class GiftCardController extends Controller
{
    public function issue(GiftCardRequest $r, GiftCardService $s): JsonResponse
    {
        $issued = $s->issue($r->salesContext(), $r->validated('currency'), $r->integer('initial_amount'),
            $r->validated('client_operation_id'), $r->validated('customer_id'), $r->date('expires_at'));

        return response()->json(['data' => [...$this->card($issued->giftCard), 'token' => $issued->token]], 201);
    }

    public function load(GiftCardRequest $r, GiftCardService $s): JsonResponse
    {
        return response()->json(['data' => $this->transaction($s->load($r->salesContext(), $r->validated('token'),
            $r->integer('amount'), $r->validated('currency'), $r->validated('client_operation_id')))], 201);
    }

    public function balance(GiftCardRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $hash = hash_hmac('sha256', trim($r->validated('token')), (string) config('app.key'));
        $card = GiftCard::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('token_hash', $hash)->first()
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The gift card was not found.');

        return response()->json(['data' => $this->card($card)]);
    }

    public function redeem(GiftCardRequest $r, GiftCardService $s): JsonResponse
    {
        return response()->json(['data' => $this->transaction($s->redeem($r->salesContext(), $r->validated('token'),
            $r->validated('order_id'), $r->integer('amount'), $r->validated('currency'), $r->validated('client_operation_id')))], 201);
    }

    public function reverse(GiftCardRequest $r, GiftCardService $s): JsonResponse
    {
        return response()->json(['data' => $this->transaction($s->reverse($r->salesContext(), $r->validated('transaction_id'),
            $r->validated('client_operation_id')))], 201);
    }

    private function card($c): array
    {
        return ['id' => $c->id, 'customer_id' => $c->customer_id, 'token_last4' => $c->token_last4,
            'currency' => $c->currency, 'balance_amount' => $c->balance_amount, 'status' => $c->status,
            'issued_at' => $c->issued_at?->toISOString(), 'expires_at' => $c->expires_at?->toISOString()];
    }

    private function transaction($t): array
    {
        return ['id' => $t->id, 'gift_card_id' => $t->gift_card_id,
            'order_id' => $t->order_id, 'original_transaction_id' => $t->original_transaction_id, 'type' => $t->type,
            'amount' => $t->amount, 'balance_after' => $t->balance_after, 'currency' => $t->currency,
            'occurred_at' => $t->occurred_at?->toISOString()];
    }
}
