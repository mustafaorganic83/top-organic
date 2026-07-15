<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BranchPaymentMethod;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\BillingRequest;
use App\Modules\Sales\Http\Requests\IndexRequest;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Modules\Sales\Services\SettlementService;
use Illuminate\Http\JsonResponse;

class BillingController extends Controller
{
    public function methods(IndexRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $methods = BranchPaymentMethod::withoutGlobalScopes()->where('tenant_id', $c->tenantId)
            ->where('branch_id', $c->branchId)->where('is_enabled', true)->with(['paymentMethod' => fn ($q) => $q->withoutGlobalScopes()
            ->where('status', 'active')->where('is_enabled', true)])->get()->filter(fn ($m) => $m->paymentMethod !== null);

        return response()->json(['data' => $methods->map(fn ($m) => ['id' => $m->paymentMethod->id, 'code' => $m->paymentMethod->code,
            'name' => $m->paymentMethod->name, 'kind' => $m->paymentMethod->kind, 'minimum_amount' => $m->minimum_amount,
            'maximum_amount' => $m->maximum_amount])->values()]);
    }

    public function capture(BillingRequest $r, SettlementService $s): JsonResponse
    {
        $payment = $s->capture($r->salesContext(), $r->validated('order_id'), $r->integer('expected_version'),
            $r->validated('payment_method_id'), $r->integer('amount'), $r->validated('idempotency_key'),
            $r->validated('client_operation_id'), $r->validated('provider_reference'),
            $r->validated('provider_snapshot', []), $r->validated('gift_card_token'));

        return response()->json(['data' => SalesResource::payment($payment)], 201);
    }

    public function payments(IndexRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $q = Payment::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)->with('method');

        return response()->json(SalesResource::paginated($q->latest('occurred_at')->paginate($r->perPage()), SalesResource::payment(...)));
    }

    public function reverse(string $payment, BillingRequest $r, SettlementService $s): JsonResponse
    {
        $m = $s->reverse($r->salesContext(), $payment, $r->integer('amount'), $r->validated('reason'),
            $r->validated('client_operation_id'));

        return response()->json(['data' => ['id' => $m->id, 'original_payment_id' => $m->original_payment_id,
            'reversal_payment_id' => $m->reversal_payment_id, 'amount' => $m->amount, 'currency' => $m->currency,
            'reason' => $m->reason, 'occurred_at' => $m->occurred_at?->toISOString()]], 201);
    }

    public function invoice(string $invoice, BillingRequest $r): JsonResponse
    {
        return response()->json(['data' => SalesResource::invoice($this->invoiceModel($r, $invoice))]);
    }

    public function receipt(string $invoice, BillingRequest $r): JsonResponse
    {
        return response()->json(['data' => [...SalesResource::invoice($this->invoiceModel($r, $invoice)), 'document_type' => 'receipt']]);
    }

    public function settlement(string $order, BillingRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $model = Order::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)
            ->whereKey($order)->first() ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The order was not found.');

        return response()->json(['data' => ['order_id' => $model->id, 'state' => $model->state,
            'currency' => $model->currency, 'total_amount' => $model->total_amount,
            'paid_amount' => $model->paid_amount, 'due_amount' => $model->due_amount,
            'settled_at' => $model->settled_at?->toISOString(), 'lock_version' => $model->lock_version]]);
    }

    private function invoiceModel(BillingRequest $r, string $id): Invoice
    {
        $c = $r->salesContext();

        return Invoice::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)
            ->whereKey($id)->first() ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The invoice was not found.');
    }
}
