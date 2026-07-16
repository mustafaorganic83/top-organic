<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\RfqService;
use Illuminate\Http\JsonResponse;

class RfqController extends Controller
{
    public function index(ProcurementRequest $request, RfqService $service): JsonResponse
    {
        $rfqs = $service->list($request->procurementContext());

        return response()->json(['data' => $rfqs->values()->all()]);
    }

    public function show(string $rfq, ProcurementRequest $request, RfqService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $rfq);

        return response()->json(['data' => $model]);
    }

    public function store(ProcurementRequest $request, RfqService $service): JsonResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:64',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.stock_item_id' => 'nullable|string',
            'items.*.description' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|min:0.000001',
            'items.*.unit' => 'required|string|max:24',
            'items.*.required_date' => 'nullable|date',
        ]);
        $rfq = $service->create($request->procurementContext(), $data);

        return response()->json(['data' => $rfq], 201);
    }

    public function send(string $rfq, ProcurementRequest $request, RfqService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->transition($request->procurementContext(), $rfq, (int) $data['expected_version'], 'sent');

        return response()->json(['data' => $model]);
    }

    public function close(string $rfq, ProcurementRequest $request, RfqService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->transition($request->procurementContext(), $rfq, (int) $data['expected_version'], 'closed');

        return response()->json(['data' => $model]);
    }
}
