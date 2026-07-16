<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\InspectionService;
use Illuminate\Http\JsonResponse;

class InspectionController extends Controller
{
    public function index(ProcurementRequest $request, InspectionService $service): JsonResponse
    {
        $items = $service->list($request->procurementContext());

        return response()->json(['data' => $items->values()->all()]);
    }

    public function store(string $receipt, ProcurementRequest $request, InspectionService $service): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string',
            'findings' => 'nullable|array',
        ]);
        $inspection = $service->create($request->procurementContext(), $receipt, $data);

        return response()->json(['data' => $inspection], 201);
    }

    public function show(string $inspection, ProcurementRequest $request, InspectionService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $inspection);

        return response()->json(['data' => $model]);
    }

    public function pass(string $inspection, ProcurementRequest $request, InspectionService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->complete($request->procurementContext(), $inspection, (int) $data['expected_version'], 'passed');

        return response()->json(['data' => $model]);
    }

    public function fail(string $inspection, ProcurementRequest $request, InspectionService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->complete($request->procurementContext(), $inspection, (int) $data['expected_version'], 'failed');

        return response()->json(['data' => $model]);
    }
}
