<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\LoanService;
use Illuminate\Http\JsonResponse;

class LoanController extends Controller
{
    public function index(HrRequest $request, LoanService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->hrContext(), $request->query('employee_id'), $request->query('status'))->values()->all()]);
    }

    public function show(string $loan, HrRequest $request, LoanService $service): JsonResponse
    {
        return response()->json(['data' => $service->find($request->hrContext(), $loan)]);
    }

    public function store(HrRequest $request, LoanService $service): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|string',
            'loan_type' => 'nullable|string|in:advance,long_term',
            'amount' => 'required|integer|min:1',
            'installments' => 'nullable|integer|min:1',
            'purpose' => 'nullable|string|max:500',
        ]);

        return response()->json(['data' => $service->request($request->hrContext(), $data)], 201);
    }

    public function approve(string $loan, HrRequest $request, LoanService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);

        return response()->json(['data' => $service->approve($request->hrContext(), $loan, (int) $data['expected_version'])]);
    }
}
