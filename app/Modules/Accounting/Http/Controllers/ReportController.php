<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function trialBalance(AccountingRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validate(['fiscal_year' => 'required|string|size:4', 'period_month' => 'nullable|integer|between:1,12']);
        $report = $service->trialBalance($request->accountingContext(),
            (string) $data['fiscal_year'],
            isset($data['period_month']) ? (int) $data['period_month'] : null);

        return response()->json(['data' => $report]);
    }

    public function incomeStatement(AccountingRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validate(['fiscal_year' => 'required|string|size:4', 'period_month' => 'nullable|integer|between:1,12', 'branch_id' => 'nullable|string']);
        $report = $service->incomeStatement($request->accountingContext(),
            (string) $data['fiscal_year'],
            isset($data['period_month']) ? (int) $data['period_month'] : null,
            $data['branch_id'] ?? null);

        return response()->json(['data' => $report]);
    }

    public function balanceSheet(AccountingRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validate(['fiscal_year' => 'required|string|size:4', 'period_month' => 'nullable|integer|between:1,12']);
        $report = $service->balanceSheet($request->accountingContext(),
            (string) $data['fiscal_year'],
            isset($data['period_month']) ? (int) $data['period_month'] : null);

        return response()->json(['data' => $report]);
    }

    public function cashFlow(AccountingRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validate(['fiscal_year' => 'required|string|size:4', 'period_month' => 'nullable|integer|between:1,12']);
        $report = $service->cashFlow($request->accountingContext(),
            (string) $data['fiscal_year'],
            isset($data['period_month']) ? (int) $data['period_month'] : null);

        return response()->json(['data' => $report]);
    }

    public function profitability(AccountingRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validate(['fiscal_year' => 'required|string|size:4', 'period_month' => 'nullable|integer|between:1,12']);
        $report = $service->profitability($request->accountingContext(),
            (string) $data['fiscal_year'],
            isset($data['period_month']) ? (int) $data['period_month'] : null);

        return response()->json(['data' => $report]);
    }

    public function branchAccounting(AccountingRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validate(['fiscal_year' => 'required|string|size:4', 'period_month' => 'nullable|integer|between:1,12']);
        $report = $service->branchAccounting($request->accountingContext(),
            (string) $data['fiscal_year'],
            isset($data['period_month']) ? (int) $data['period_month'] : null);

        return response()->json(['data' => $report]);
    }

    public function generalLedger(AccountingRequest $request, ReportService $service): JsonResponse
    {
        $data = $request->validate(['account_id' => 'required|string', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $report = $service->generalLedger($request->accountingContext(),
            (string) $data['account_id'],
            $data['from'] ?? null, $data['to'] ?? null);

        return response()->json(['data' => $report]);
    }
}
