<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\SupplierService;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    public function index(ProcurementRequest $request, SupplierService $service): JsonResponse
    {
        $suppliers = $service->list($request->procurementContext(), $request->query('status'));

        return response()->json(['data' => $suppliers->values()->all()]);
    }

    public function show(string $supplier, ProcurementRequest $request, SupplierService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $supplier);

        return response()->json(['data' => $model]);
    }

    public function update(string $supplier, ProcurementRequest $request, SupplierService $service): JsonResponse
    {
        $data = $request->validate([
            'category' => 'nullable|string|max:50',
            'rating' => 'nullable|integer|min:0|max:100',
            'lead_time_days' => 'nullable|integer|min:0',
            'payment_terms' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,inactive',
            'expected_version' => 'required|integer|min:0',
        ]);
        $model = $service->update($request->procurementContext(), $supplier,
            (int) $data['expected_version'], $data);

        return response()->json(['data' => $model]);
    }
}
