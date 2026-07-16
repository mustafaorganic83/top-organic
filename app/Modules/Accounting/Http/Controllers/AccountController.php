<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function index(AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $accounts = $service->list($request->accountingContext(), $request->query('type'));

        return response()->json(['data' => $accounts->values()->all()]);
    }

    public function show(string $account, AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $model = $service->find($request->accountingContext(), $account);

        return response()->json(['data' => $model]);
    }

    public function store(AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:200',
            'type' => 'required|string|in:asset,liability,equity,revenue,expense,cogs',
            'subtype' => 'nullable|string|max:50',
            'parent_id' => 'nullable|string',
            'allow_direct_posting' => 'boolean',
            'currency' => 'nullable|string|size:3',
            'status' => 'nullable|string|in:active,inactive',
        ]);
        $account = $service->create($request->accountingContext(), $data);

        return response()->json(['data' => $account], 201);
    }

    public function update(string $account, AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'nullable|string|max:20',
            'name' => 'nullable|string|max:200',
            'subtype' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:active,inactive',
            'allow_direct_posting' => 'boolean',
            'currency' => 'nullable|string|size:3',
            'expected_version' => 'required|integer|min:0',
        ]);
        $model = $service->update($request->accountingContext(), $account,
            (int) $data['expected_version'], $data);

        return response()->json(['data' => $model]);
    }

    public function destroy(string $account, AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $service->delete($request->accountingContext(), $account);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function costCenters(AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $items = $service->costCenters($request->accountingContext());

        return response()->json(['data' => $items->values()->all()]);
    }

    public function storeCostCenter(AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:200',
            'type' => 'nullable|string|in:branch,department,project',
            'branch_id' => 'nullable|string',
            'parent_id' => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        $cc = $service->createCostCenter($request->accountingContext(), $data);

        return response()->json(['data' => $cc], 201);
    }

    public function updateCostCenter(string $costCenter, AccountingRequest $request, ChartOfAccountsService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:200',
            'type' => 'nullable|string|in:branch,department,project',
            'is_active' => 'boolean',
            'expected_version' => 'required|integer|min:0',
        ]);
        $model = $service->updateCostCenter($request->accountingContext(), $costCenter,
            (int) $data['expected_version'], $data);

        return response()->json(['data' => $model]);
    }
}
