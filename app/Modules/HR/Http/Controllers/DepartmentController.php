<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\DepartmentService;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(HrRequest $request, DepartmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->hrContext())->values()->all()]);
    }

    public function show(string $department, HrRequest $request, DepartmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->find($request->hrContext(), $department)]);
    }

    public function store(HrRequest $request, DepartmentService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:200',
            'type' => 'nullable|string|in:department,section,team',
            'parent_id' => 'nullable|string',
        ]);

        return response()->json(['data' => $service->create($request->hrContext(), $data)], 201);
    }

    public function update(string $department, HrRequest $request, DepartmentService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:200',
            'type' => 'nullable|string|in:department,section,team',
            'parent_id' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
            'expected_version' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $service->update($request->hrContext(), $department, (int) $data['expected_version'], $data)]);
    }
}
