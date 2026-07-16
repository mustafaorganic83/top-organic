<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\PayrollService;
use Illuminate\Http\JsonResponse;

class PayrollController extends Controller
{
    public function index(HrRequest $request, PayrollService $service): JsonResponse
    {
        return response()->json(['data' => $service->listRuns($request->hrContext())->values()->all()]);
    }

    public function show(string $run, HrRequest $request, PayrollService $service): JsonResponse
    {
        return response()->json(['data' => $service->findRun($request->hrContext(), $run)]);
    }

    public function store(HrRequest $request, PayrollService $service): JsonResponse
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        return response()->json(['data' => $service->createRun($request->hrContext(), $data)], 201);
    }

    public function calculate(string $run, HrRequest $request, PayrollService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $service->calculate($request->hrContext(), $run, (int) $data['expected_version'])]);
    }

    public function approve(string $run, HrRequest $request, PayrollService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $service->approve($request->hrContext(), $run, (int) $data['expected_version'])]);
    }

    public function payslip(string $run, HrRequest $request, PayrollService $service): JsonResponse
    {
        $employeeId = (string) $request->query('employee_id', '');

        return response()->json(['data' => $service->findPayslip($request->hrContext(), $run, $employeeId)]);
    }

    public function addAdjustment(string $run, HrRequest $request, PayrollService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|string',
            'type' => 'required|string|in:bonus,penalty',
            'amount' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:500',
        ]);

        return response()->json(['data' => $service->addAdjustment($request->hrContext(), $run, $data)], 201);
    }
}
