<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\SupplierEvaluationService;
use Illuminate\Http\JsonResponse;

class SupplierEvaluationController extends Controller
{
    public function index(string $supplier, ProcurementRequest $request, SupplierEvaluationService $service): JsonResponse
    {
        $items = $service->list($request->procurementContext(), $supplier);

        return response()->json(['data' => $items->values()->all()]);
    }

    public function store(string $supplier, ProcurementRequest $request, SupplierEvaluationService $service): JsonResponse
    {
        $data = $request->validate([
            'score' => 'required|numeric|min:0|max:100',
            'criteria' => 'nullable|array',
            'notes' => 'nullable|string',
            'evaluated_at' => 'nullable|date',
        ]);
        $eval = $service->create($request->procurementContext(), $supplier, $data);

        return response()->json(['data' => $eval], 201);
    }

    public function show(string $supplier, string $evaluation, ProcurementRequest $request, SupplierEvaluationService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $evaluation);

        return response()->json(['data' => $model]);
    }
}
