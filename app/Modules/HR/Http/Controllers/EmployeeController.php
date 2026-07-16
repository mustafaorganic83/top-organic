<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Services\HistoryService;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function index(HrRequest $request, EmployeeService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->hrContext(), $request->query('status'))->values()->all()]);
    }

    public function show(string $employee, HrRequest $request, EmployeeService $service): JsonResponse
    {
        return response()->json(['data' => $service->find($request->hrContext(), $employee)]);
    }

    public function store(HrRequest $request, EmployeeService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_number' => 'required|string|max:32',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:30',
            'national_id' => 'nullable|string|max:32',
            'position' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'employment_type' => 'nullable|string|in:full_time,part_time,contract',
            'department_id' => 'nullable|string',
            'manager_id' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'base_salary_amount' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
        ]);

        return response()->json(['data' => $service->create($request->hrContext(), $data)], 201);
    }

    public function update(string $employee, HrRequest $request, EmployeeService $service): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'position' => 'nullable|string|max:100',
            'department_id' => 'nullable|string',
            'manager_id' => 'nullable|string',
            'status' => 'nullable|string|in:active,on_leave,terminated',
            'base_salary_amount' => 'nullable|integer|min:0',
            'termination_date' => 'nullable|date',
            'expected_version' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $service->update($request->hrContext(), $employee, (int) $data['expected_version'], $data)]);
    }

    public function history(string $employee, HrRequest $request, HistoryService $service): JsonResponse
    {
        return response()->json(['data' => $service->forEmployee($request->hrContext(), $employee, $request->query('entity_type'))->values()->all()]);
    }
}
