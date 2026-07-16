<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\PerformanceService;
use Illuminate\Http\JsonResponse;

class PerformanceController extends Controller
{
    public function index(HrRequest $request, PerformanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->hrContext(), $request->query('employee_id'))->values()->all()]);
    }

    public function show(string $review, HrRequest $request, PerformanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->find($request->hrContext(), $review)]);
    }

    public function store(HrRequest $request, PerformanceService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|string',
            'review_period_start' => 'nullable|date',
            'review_period_end' => 'nullable|date',
            'score' => 'nullable|integer|min:0|max:100',
            'rating' => 'nullable|string|max:32',
            'comments' => 'nullable|string|max:2000',
        ]);

        return response()->json(['data' => $service->create($request->hrContext(), $data)], 201);
    }

    public function acknowledge(string $review, HrRequest $request, PerformanceService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $service->acknowledge($request->hrContext(), $review, (int) $data['expected_version'])]);
    }
}
