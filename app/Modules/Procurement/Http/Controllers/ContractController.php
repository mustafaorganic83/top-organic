<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\ContractService;
use Illuminate\Http\JsonResponse;

class ContractController extends Controller
{
    public function index(ProcurementRequest $request, ContractService $service): JsonResponse
    {
        $contracts = $service->list($request->procurementContext(), $request->query('status'));

        return response()->json(['data' => $contracts->values()->all()]);
    }

    public function show(string $contract, ProcurementRequest $request, ContractService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $contract);

        return response()->json(['data' => $model]);
    }

    public function store(ProcurementRequest $request, ContractService $service): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => 'required|string',
            'reference' => 'required|string|max:64',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'value_amount' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'terms' => 'nullable|string',
            'signed_at' => 'nullable|date',
        ]);
        $contract = $service->create($request->procurementContext(), $data);

        return response()->json(['data' => $contract], 201);
    }

    public function terminate(string $contract, ProcurementRequest $request, ContractService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->terminate($request->procurementContext(), $contract, (int) $data['expected_version']);

        return response()->json(['data' => $model]);
    }
}
