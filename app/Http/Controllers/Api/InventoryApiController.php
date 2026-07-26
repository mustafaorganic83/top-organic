<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\StockLevelResource;
use App\Models\InventoryMovement;
use App\Models\StockLevel;
use App\Models\StockItem;
use App\Services\Inventory\InventoryCostingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryApiController extends Controller
{
    public function stockLevels(Request $request): AnonymousResourceCollection
    {
        $q = StockLevel::query()->with('warehouse','stockable');
        if ($w = $request->query('warehouse_id')) { $q->where('warehouse_id',$w); }
        if ($t = $request->query('stockable_type')) { $q->where('stockable_type',$t); }
        return StockLevelResource::collection($q->paginate($request->integer('per_page', 25)));
    }

    public function movements(Request $request): AnonymousResourceCollection
    {
        $q = InventoryMovement::query()->orderByDesc('occurred_at');
        if ($w = $request->query('warehouse_id')) { $q->where('warehouse_id',$w); }
        if ($r = $request->query('reason')) { $q->where('reason',$r); }
        if ($sid = $request->query('stockable_id')) { $q->where('stockable_id',$sid); }
        if ($st = $request->query('stockable_type')) { $q->where('stockable_type',$st); }
        return InventoryMovementResource::collection($q->paginate($request->integer('per_page', 25)));
    }

    public function createMovement(Request $request, InventoryCostingService $svc)
    {
        $data = $request->validate([
            'branch_id' => ['required','string'],
            'warehouse_id' => ['required','string'],
            'stockable_type' => ['required','string'],
            'stockable_id' => ['required'],
            'quantity_delta' => ['required','numeric','not_in:0'],
            'unit' => ['required','string','max:32'],
            'reason' => ['required','string','max:64'],
            'occurred_at' => ['nullable','date'],
            'reference_type' => ['nullable','string','max:120'],
            'reference_id' => ['nullable'],
            'client_operation_id' => ['nullable','string','max:120'],
            'unit_cost_amount' => ['nullable','integer','min:0'],
        ]);
        $m = InventoryMovement::query()->create(array_merge($data, [
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]));
        $svc->onMovementCreated($m);
        return new InventoryMovementResource($m->refresh());
    }
}
