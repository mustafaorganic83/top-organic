<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\ReceivingService;
use Illuminate\Http\JsonResponse;

class ReceivingController extends Controller
{
    public function index(ProcurementRequest $request, ReceivingService $service): JsonResponse
    {
        $receipts = $service->list($request->procurementContext());

        return response()->json(['data' => $receipts->values()->all()]);
    }

    public function show(string $receipt, ProcurementRequest $request, ReceivingService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $receipt);

        return response()->json(['data' => $model]);
    }

    public function store(ProcurementRequest $request, ReceivingService $service): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => 'nullable|string',
            'supplier_id' => 'nullable|string',
            'reference' => 'required|string|max:64',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'nullable|string',
            'items.*.stock_item_id' => 'nullable|string',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity_ordered' => 'nullable|numeric|min:0',
            'items.*.quantity_received' => 'required|numeric|min:0.000001',
            'items.*.unit' => 'required|string|max:24',
            'items.*.unit_price_amount' => 'nullable|integer|min:0',
        ]);
        $receipt = $service->create($request->procurementContext(), $data);

        return response()->json(['data' => $receipt], 201);
    }

    public function post(string $receipt, ProcurementRequest $request, ReceivingService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->post($request->procurementContext(), $receipt, (int) $data['expected_version']);

        return response()->json(['data' => $model]);
    }
}
