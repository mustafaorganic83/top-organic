<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\PurchaseRequestService;
use Illuminate\Http\JsonResponse;

class PurchaseRequestController extends Controller
{
    public function index(ProcurementRequest $request, PurchaseRequestService $service): JsonResponse
    {
        $prs = $service->list($request->procurementContext());

        return response()->json(['data' => $prs->values()->all()]);
    }

    public function show(string $pr, ProcurementRequest $request, PurchaseRequestService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $pr);

        return response()->json(['data' => $model]);
    }

    public function store(ProcurementRequest $request, PurchaseRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:64',
            'warehouse_id' => 'nullable|string',
            'source' => 'nullable|string|in:manual,auto_reorder',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.stock_item_id' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.000001',
            'items.*.unit' => 'required|string|max:24',
            'items.*.estimated_unit_cost_amount' => 'nullable|integer|min:0',
        ]);
        $pr = $service->create($request->procurementContext(), $data);

        return response()->json(['data' => $pr], 201);
    }

    public function approve(string $pr, ProcurementRequest $request, PurchaseRequestService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->approve($request->procurementContext(), $pr, (int) $data['expected_version']);

        return response()->json(['data' => $model]);
    }
}
