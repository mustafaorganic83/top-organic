<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\ProjectService;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function index(AccountingRequest $request, ProjectService $service): JsonResponse
    {
        $projects = $service->list($request->accountingContext(), $request->query('status'));

        return response()->json(['data' => $projects->values()->all()]);
    }

    public function show(string $project, AccountingRequest $request, ProjectService $service): JsonResponse
    {
        $model = $service->find($request->accountingContext(), $project);

        return response()->json(['data' => $model]);
    }

    public function store(AccountingRequest $request, ProjectService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:200',
            'status' => 'nullable|string|in:active,completed,on_hold',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'budget_amount' => 'nullable|integer|min:0',
            'branch_id' => 'nullable|string',
        ]);
        $project = $service->create($request->accountingContext(), $data);

        return response()->json(['data' => $project], 201);
    }

    public function update(string $project, AccountingRequest $request, ProjectService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:200',
            'status' => 'nullable|string|in:active,completed,on_hold',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'budget_amount' => 'nullable|integer|min:0',
            'expected_version' => 'required|integer|min:0',
        ]);
        $model = $service->update($request->accountingContext(), $project,
            (int) $data['expected_version'], $data);

        return response()->json(['data' => $model]);
    }
}
