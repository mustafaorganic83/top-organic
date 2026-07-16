<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\QuotationService;
use Illuminate\Http\JsonResponse;

class QuotationController extends Controller
{
    public function index(ProcurementRequest $request, QuotationService $service): JsonResponse
    {
        $quotations = $service->list($request->procurementContext(), $request->query('rfq_id'));

        return response()->json(['data' => $quotations->values()->all()]);
    }

    public function show(string $quotation, ProcurementRequest $request, QuotationService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $quotation);

        return response()->json(['data' => $model]);
    }

    public function store(ProcurementRequest $request, QuotationService $service): JsonResponse
    {
        $data = $request->validate([
            'rfq_id' => 'nullable|string',
            'supplier_id' => 'required|string',
            'reference' => 'required|string|max:64',
            'currency' => 'nullable|string|size:3',
            'total_amount' => 'nullable|integer|min:0',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.stock_item_id' => 'nullable|string',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.000001',
            'items.*.unit' => 'required|string|max:24',
            'items.*.unit_price_amount' => 'required|integer|min:0',
            'items.*.total_amount' => 'nullable|integer|min:0',
        ]);
        $quotation = $service->create($request->procurementContext(), $data);

        return response()->json(['data' => $quotation], 201);
    }

    public function shortlist(string $quotation, ProcurementRequest $request, QuotationService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->transition($request->procurementContext(), $quotation, (int) $data['expected_version'], 'shortlisted');

        return response()->json(['data' => $model]);
    }

    public function award(string $quotation, ProcurementRequest $request, QuotationService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->transition($request->procurementContext(), $quotation, (int) $data['expected_version'], 'awarded');

        return response()->json(['data' => $model]);
    }

    public function reject(string $quotation, ProcurementRequest $request, QuotationService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->transition($request->procurementContext(), $quotation, (int) $data['expected_version'], 'rejected');

        return response()->json(['data' => $model]);
    }
}
