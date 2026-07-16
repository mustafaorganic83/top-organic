<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Http\JsonResponse;

class JournalController extends Controller
{
    public function index(AccountingRequest $request, JournalService $service): JsonResponse
    {
        $entries = $service->list(
            $request->accountingContext(),
            $request->query('fiscal_year'),
            $request->query('status'),
        );

        return response()->json(['data' => $entries->values()->all()]);
    }

    public function show(string $journal, AccountingRequest $request, JournalService $service): JsonResponse
    {
        $entry = $service->find($request->accountingContext(), $journal);

        return response()->json(['data' => $entry]);
    }

    public function store(AccountingRequest $request, JournalService $service): JsonResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:50',
            'entry_date' => 'required|date',
            'description' => 'nullable|string',
            'source' => 'nullable|string',
            'branch_id' => 'nullable|string',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|string',
            'lines.*.debit_amount' => 'required|integer|min:0',
            'lines.*.credit_amount' => 'required|integer|min:0',
            'lines.*.cost_center_id' => 'nullable|string',
            'lines.*.project_id' => 'nullable|string',
            'lines.*.description' => 'nullable|string',
            'lines.*.currency' => 'nullable|string|size:3',
        ]);
        $entry = $service->create($request->accountingContext(), $data);

        return response()->json(['data' => $entry], 201);
    }

    public function post(string $journal, AccountingRequest $request, JournalService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $entry = $service->post($request->accountingContext(), $journal, (int) $data['expected_version']);

        return response()->json(['data' => $entry]);
    }

    public function reverse(string $journal, AccountingRequest $request, JournalService $service): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => 'required|integer|min:0',
            'reference' => 'required|string|max:50',
            'description' => 'required|string',
        ]);
        $reversal = $service->reverse(
            $request->accountingContext(), $journal,
            (int) $data['expected_version'], (string) $data['reference'], (string) $data['description'],
        );

        return response()->json(['data' => $reversal], 201);
    }
}
