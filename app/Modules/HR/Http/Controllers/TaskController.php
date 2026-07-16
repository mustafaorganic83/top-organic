<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\TaskService;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
    public function index(HrRequest $request, TaskService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->hrContext(), $request->query('employee_id'), $request->query('status'))->values()->all()]);
    }

    public function show(string $task, HrRequest $request, TaskService $service): JsonResponse
    {
        return response()->json(['data' => $service->find($request->hrContext(), $task)]);
    }

    public function store(HrRequest $request, TaskService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|string',
            'title' => 'required|string|max:256',
            'description' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
            'priority' => 'nullable|string|in:low,normal,high,urgent',
        ]);

        return response()->json(['data' => $service->create($request->hrContext(), $data)], 201);
    }

    public function complete(string $task, HrRequest $request, TaskService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $service->complete($request->hrContext(), $task, (int) $data['expected_version'])]);
    }

    public function update(string $task, HrRequest $request, TaskService $service): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:256',
            'description' => 'nullable|string|max:2000',
            'due_date' => 'nullable|date',
            'priority' => 'nullable|string|in:low,normal,high,urgent',
            'status' => 'nullable|string|in:open,in_progress,done,cancelled',
            'expected_version' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $service->update($request->hrContext(), $task, (int) $data['expected_version'], $data)]);
    }
}
