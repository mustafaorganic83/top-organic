<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\BankService;
use Illuminate\Http\JsonResponse;

class BankController extends Controller
{
    public function index(AccountingRequest $request, BankService $service): JsonResponse
    {
        $accounts = $service->list($request->accountingContext());

        return response()->json(['data' => $accounts->values()->all()]);
    }

    public function show(string $bank, AccountingRequest $request, BankService $service): JsonResponse
    {
        $account = $service->find($request->accountingContext(), $bank);

        return response()->json(['data' => $account]);
    }

    public function store(AccountingRequest $request, BankService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:200',
            'account_id' => 'required|string',
            'bank_name' => 'nullable|string|max:200',
            'account_number' => 'nullable|string|max:100',
            'iban' => 'nullable|string|max:100',
            'type' => 'nullable|string|in:checking,savings,cash_box',
            'currency' => 'nullable|string|size:3',
            'opening_balance' => 'nullable|integer',
            'branch_id' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
        ]);
        $bank = $service->create($request->accountingContext(), $data);

        return response()->json(['data' => $bank], 201);
    }

    public function statement(string $bank, AccountingRequest $request, BankService $service): JsonResponse
    {
        $transactions = $service->statement($request->accountingContext(), $bank,
            $request->query('from'), $request->query('to'));

        return response()->json(['data' => $transactions->values()->all()]);
    }

    public function reconcile(string $bank, AccountingRequest $request, BankService $service): JsonResponse
    {
        $request->validate(['transaction_ids' => 'required|array|min:1', 'transaction_ids.*' => 'string']);
        $count = $service->reconcile($request->accountingContext(), $bank,
            $request->validated('transaction_ids'));

        return response()->json(['data' => ['reconciled_count' => $count]]);
    }
}
