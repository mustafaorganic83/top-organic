<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\IndexRequest;
use App\Modules\Sales\Http\Requests\OrderRequest;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Modules\Sales\Services\DiscountCouponService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\SplitMergeTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(IndexRequest $request): JsonResponse
    {
        $c = $request->salesContext();
        $q = Order::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId);
        if ($request->filled('state')) {
            $q->where('state', $request->validated('state'));
        }
        if ($request->filled('type')) {
            $q->where('type', $request->validated('type'));
        }

        return response()->json(SalesResource::paginated($q->latest()->paginate($request->perPage()), SalesResource::order(...)));
    }

    public function store(OrderRequest $r, OrderService $s): JsonResponse
    {
        $data = $r->safe()->only(['type', 'currency', 'source', 'source_reference', 'idempotency_key',
            'table_session_id', 'pos_shift_id', 'customer_id', 'client_operation_id']);
        if ($r->has('delivery')) {
            $data['delivery'] = $r->validated('delivery');
        } elseif ($r->has('address_snapshot')) {
            $data['delivery'] = $this->deliveryPayload($r);
        }

        return response()->json(['data' => SalesResource::order($s->create($r->salesContext(), $data))], 201);
    }

    public function show(string $order, OrderRequest $r): JsonResponse
    {
        return response()->json(['data' => SalesResource::order($this->order($r, $order))]);
    }

    public function tracking(string $order, OrderRequest $r): JsonResponse
    {
        $model = $this->order($r, $order)->load(['events', 'delivery']);
        $timeline = $model->events->map(fn ($e) => ['id' => $e->id, 'sequence' => $e->sequence,
            'type' => $e->event_type, 'occurred_at' => $e->occurred_at?->toISOString()]);
        if ($model->delivery !== null) {
            $timeline->push(['id' => $model->delivery->id, 'type' => 'Delivery'.ucfirst($model->delivery->state),
                'occurred_at' => $model->delivery->updated_at?->toISOString()]);
        }

        return response()->json(['data' => ['order_id' => $model->id, 'number' => $model->number,
            'state' => $model->state, 'timeline' => $timeline->sortBy('occurred_at')->values()]]);
    }

    public function addItem(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->addItem($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('variant_id'), $r->validated('quantity'), $r->validated('modifiers', []),
            $r->validated('channel', 'pos'), $r->validated('client_operation_id')), 201);
    }

    public function updateItem(string $order, string $item, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->updateItem($r->salesContext(), $order, $item, $r->integer('expected_version'),
            $r->safe()->only(['quantity', 'modifiers', 'course_number', 'seat_number', 'notes']), $r->validated('client_operation_id')));
    }

    public function removeItem(string $order, string $item, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->removeItem($r->salesContext(), $order, $item,
            $r->integer('expected_version'), $r->validated('client_operation_id')));
    }

    public function customer(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->setCustomer($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('customer_id'), $r->validated('client_operation_id')));
    }

    public function delivery(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->setDelivery($r->salesContext(), $order, $r->integer('expected_version'),
            $this->deliveryPayload($r), $r->validated('client_operation_id')));
    }

    public function place(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->place($r->salesContext(), $order, $r->integer('expected_version'), $r->validated('client_operation_id')));
    }

    public function state(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->transition($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('state'), $r->validated('client_operation_id')));
    }

    public function manualDiscount(string $order, OrderRequest $r, DiscountCouponService $s): JsonResponse
    {
        $model = $r->validated('discount_type') === 'fixed'
            ? $s->applyManualFixed($r->salesContext(), $order, $r->integer('expected_version'), $r->integer('amount'),
                $r->validated('reason'), $r->validated('client_operation_id'))
            : $s->applyManualPercent($r->salesContext(), $order, $r->integer('expected_version'), $r->integer('rate_bps'),
                $r->validated('reason'), $r->validated('client_operation_id'), null, $r->validated('maximum_amount'));

        return $this->orderResponse($model);
    }

    public function membershipDiscount(string $order, OrderRequest $r, DiscountCouponService $s): JsonResponse
    {
        return $this->orderResponse($s->applyMembership($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('membership_id'), $r->validated('client_operation_id')));
    }

    public function couponDiscount(string $order, OrderRequest $r, DiscountCouponService $s): JsonResponse
    {
        return $this->orderResponse($s->redeemCoupon($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('coupon_token'), $r->validated('client_operation_id')));
    }

    public function charges(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->replaceCharges($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('charges'), $r->validated('client_operation_id')));
    }

    public function tip(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        return $this->orderResponse($s->addTip($r->salesContext(), $order, $r->integer('expected_version'),
            $r->integer('amount'), $r->validated('client_operation_id')));
    }

    public function split(string $order, OrderRequest $r, SplitMergeTransferService $s): JsonResponse
    {
        return $this->orderResponse($s->split($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('selections'), $r->validated('client_operation_id')), 201);
    }

    public function merge(string $order, OrderRequest $r, SplitMergeTransferService $s): JsonResponse
    {
        return $this->orderResponse($s->merge($r->salesContext(), $order, $r->validated('source_order_id'),
            $r->integer('expected_version'), $r->integer('source_version'), $r->validated('client_operation_id')));
    }

    public function transferTable(string $order, OrderRequest $r, SplitMergeTransferService $s): JsonResponse
    {
        return $this->orderResponse($s->transferTable($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('target_table_session_id'), $r->validated('client_operation_id')));
    }

    public function transferCustomer(string $order, OrderRequest $r, SplitMergeTransferService $s): JsonResponse
    {
        return $this->orderResponse($s->transferCustomer($r->salesContext(), $order, $r->integer('expected_version'),
            $r->validated('customer_id'), $r->validated('client_operation_id')));
    }

    public function quote(string $order, OrderRequest $r): JsonResponse
    {
        return response()->json(['data' => SalesResource::order($this->order($r, $order))]);
    }

    public function recalculate(string $order, OrderRequest $r, OrderService $s): JsonResponse
    {
        $model = DB::transaction(function () use ($order, $r, $s) {
            $model = $s->orderForUpdate($r->salesContext(), $order);
            $s->assertMutableVersion($model, $r->integer('expected_version'));

            return $s->recalculateLocked($r->salesContext(), $model)->refresh();
        }, 3);

        return $this->orderResponse($model);
    }

    private function order(OrderRequest $r, string $id): Order
    {
        $c = $r->salesContext();

        return Order::withoutGlobalScopes()->where('tenant_id', $c->tenantId)
            ->where('branch_id', $c->branchId)->whereKey($id)->first()
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The order was not found.');
    }

    private function orderResponse(Order $order, int $status = 200): JsonResponse
    {
        return response()->json(['data' => SalesResource::order($order)], $status);
    }

    private function deliveryPayload(OrderRequest $r): array
    {
        return $r->safe()->only(['address_snapshot', 'contact_snapshot', 'customer_address_id', 'provider',
            'provider_reference', 'fee_amount', 'promised_at']);
    }
}
