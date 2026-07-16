<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\LeaveService;
use Illuminate\Http\JsonResponse;

class LeaveController extends Controller
{
    public function index(HrRequest $request, LeaveService $service): JsonResponse
    {
        $leaves = $service->list($request->hrContext(), $request->query('employee_id'), $request->query('status'));

        return response()->json(['data' => $leaves->values()->all()]);
    }

    public function show(string $leave, HrRequest $request, LeaveService $service): JsonResponse
    {
        return response()->json(['data' => $service->find($request->hrContext(), $leave)]);
    }

    public function store(HrRequest $request, LeaveService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|string',
            'leave_type' => 'nullable|string|in:annual,sick,unpaid,emergency',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'days' => 'nullable|numeric|min:0.5',
            'reason' => 'nullable|string|max:500',
        ]);

        return response()->json(['data' => $service->create($request->hrContext(), $data)], 201);
    }

    public function approve(string $leave, HrRequest $request, LeaveService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $service->approve($request->hrContext(), $leave, (int) $data['expected_version'])]);
    }

    public function reject(string $leave, HrRequest $request, LeaveService $service): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        return response()->json(['data' => $service->reject($request->hrContext(), $leave, (int) $data['expected_version'], $data['reason'] ?? '')]);
    }
}
