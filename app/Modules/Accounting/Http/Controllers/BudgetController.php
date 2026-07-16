<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\BudgetService;
use Illuminate\Http\JsonResponse;

class BudgetController extends Controller
{
    public function index(AccountingRequest $request, BudgetService $service): JsonResponse
    {
        $budgets = $service->list($request->accountingContext(),
            $request->query('fiscal_year'), $request->query('branch_id'));

        return response()->json(['data' => $budgets->values()->all()]);
    }

    public function store(AccountingRequest $request, BudgetService $service): JsonResponse
    {
        $data = $request->validate([
            'account_id' => 'required|string',
            'cost_center_id' => 'nullable|string',
            'branch_id' => 'nullable|string',
            'fiscal_year' => 'required|string|size:4',
            'period_month' => 'required|integer|between:1,12',
            'budgeted_amount' => 'required|integer|min:0',
        ]);
        $budget = $service->create($request->accountingContext(), $data);

        return response()->json(['data' => $budget], 201);
    }

    public function update(string $budget, AccountingRequest $request, BudgetService $service): JsonResponse
    {
        $data = $request->validate(['budgeted_amount' => 'required|integer|min:0']);
        $model = $service->update($request->accountingContext(), $budget, $data);

        return response()->json(['data' => $model]);
    }

    public function approve(string $budget, AccountingRequest $request, BudgetService $service): JsonResponse
    {
        $model = $service->approve($request->accountingContext(), $budget);

        return response()->json(['data' => $model]);
    }

    public function variance(AccountingRequest $request, BudgetService $service): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year' => 'required|string|size:4',
            'period_month' => 'nullable|integer|between:1,12',
        ]);
        $report = $service->variance($request->accountingContext(),
            (string) $data['fiscal_year'],
            isset($data['period_month']) ? (int) $data['period_month'] : null);

        return response()->json(['data' => $report]);
    }
}
