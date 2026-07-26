<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductionOrderResource;
use App\Models\InventoryMovement;
use App\Models\ProductionOrder;
use App\Models\SemiFinishedProduct;
use App\Services\Inventory\InventoryCostingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductionApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProductionOrder::class);
        $q = ProductionOrder::query()->orderByDesc('scheduled_at');
        if ($w = $request->query('warehouse_id')) { $q->where('warehouse_id',$w); }
        if ($s = $request->query('status')) { $q->where('status',$s); }
        return ProductionOrderResource::collection($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): ProductionOrderResource
    {
        $this->authorize('create', ProductionOrder::class);
        $data = $request->validate([
            'branch_id' => ['required','string'],
            'warehouse_id' => ['required','string'],
            'prepared_recipe_id' => ['required'],
            'planned_qty' => ['required','numeric'],
            'uom_id' => ['nullable'],
            'scheduled_at' => ['nullable','date'],
        ]);
        $data['status'] = 'planned';
        $po = ProductionOrder::query()->create($data);
        return new ProductionOrderResource($po);
    }

    public function show(ProductionOrder $order): ProductionOrderResource
    {
        $this->authorize('view', $order);
        return new ProductionOrderResource($order);
    }

    public function update(Request $request, ProductionOrder $order): ProductionOrderResource
    {
        $this->authorize('update', $order);
        $data = $request->validate([
            'planned_qty' => ['sometimes','numeric'],
            'scheduled_at' => ['sometimes','date'],
            'status' => ['sometimes','string'],
        ]);
        $order->fill($data)->save();
        return new ProductionOrderResource($order->refresh());
    }

    public function start(ProductionOrder $order): ProductionOrderResource
    {
        $this->authorize('update', $order);
        $order->status = 'in_progress';
        $order->started_at = now();
        $order->save();
        return new ProductionOrderResource($order->refresh());
    }

    public function complete(Request $request, ProductionOrder $order, InventoryCostingService $svc): ProductionOrderResource
    {
        $this->authorize('update', $order);
        $data = $request->validate([
            'actual_qty' => ['required','numeric','min:0.000001'],
            'occurred_at' => ['nullable','date'],
        ]);
        $order->actual_qty = (float)$data['actual_qty'];
        $order->status = 'completed';
        $order->completed_at = now();
        $order->save();

        // Post a receipt for the produced semi-finished product
        $occurred = Carbon::parse($data['occurred_at'] ?? now());
        $m = InventoryMovement::query()->create([
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'stockable_type' => SemiFinishedProduct::class,
            'stockable_id' => $order->prepared_recipe_id,
            'quantity_delta' => (float)$order->actual_qty,
            'unit' => 'unit',
            'reason' => 'production',
            'occurred_at' => $occurred,
            'reference_type' => 'production_order',
            'reference_id' => $order->id,
        ]);
        $svc->onMovementCreated($m);

        // NOTE: Consumption postings for ingredients are not handled here yet.
        return new ProductionOrderResource($order->refresh());
    }
}
