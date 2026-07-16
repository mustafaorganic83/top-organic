<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;

class PurchaseOrderController extends Controller
{
    public function index(ProcurementRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $pos = $service->list($request->procurementContext());

        return response()->json(['data' => $pos->values()->all()]);
    }

    public function show(string $po, ProcurementRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $po);

        return response()->json(['data' => $model]);
    }

    public function store(ProcurementRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => 'required|string',
            'quotation_id' => 'nullable|string',
            'reference' => 'required|string|max:64',
            'currency' => 'nullable|string|size:3',
            'total_amount' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.stock_item_id' => 'nullable|string',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.000001',
            'items.*.unit' => 'required|string|max:24',
            'items.*.unit_price_amount' => 'nullable|integer|min:0',
            'items.*.total_amount' => 'nullable|integer|min:0',
        ]);
        $po = $service->create($request->procurementContext(), $data);

        return response()->json(['data' => $po], 201);
    }

    public function approve(string $po, ProcurementRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->transition($request->procurementContext(), $po, (int) $data['expected_version'], 'approved');

        return response()->json(['data' => $model]);
    }

    public function send(string $po, ProcurementRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->transition($request->procurementContext(), $po, (int) $data['expected_version'], 'sent');

        return response()->json(['data' => $model]);
    }
}
