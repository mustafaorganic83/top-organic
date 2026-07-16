<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Http\Requests\ProcurementRequest;
use App\Modules\Procurement\Services\PaymentScheduleService;
use Illuminate\Http\JsonResponse;

class PaymentScheduleController extends Controller
{
    public function index(ProcurementRequest $request, PaymentScheduleService $service): JsonResponse
    {
        $schedules = $service->list($request->procurementContext(), $request->query('status'));

        return response()->json(['data' => $schedules->values()->all()]);
    }

    public function show(string $schedule, ProcurementRequest $request, PaymentScheduleService $service): JsonResponse
    {
        $model = $service->find($request->procurementContext(), $schedule);

        return response()->json(['data' => $model]);
    }

    public function store(ProcurementRequest $request, PaymentScheduleService $service): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => 'required|string',
            'purchase_order_id' => 'nullable|string',
            'vendor_contract_id' => 'nullable|string',
            'reference' => 'required|string|max:64',
            'due_date' => 'required|date',
            'amount' => 'required|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string',
        ]);
        $schedule = $service->create($request->procurementContext(), $data);

        return response()->json(['data' => $schedule], 201);
    }

    public function markPaid(string $schedule, ProcurementRequest $request, PaymentScheduleService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->markPaid($request->procurementContext(), $schedule, (int) $data['expected_version']);

        return response()->json(['data' => $model]);
    }
}
