<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\ApService;
use Illuminate\Http\JsonResponse;

class ApController extends Controller
{
    public function invoices(AccountingRequest $request, ApService $service): JsonResponse
    {
        $invoices = $service->invoices($request->accountingContext(),
            $request->query('supplier_id'), $request->query('status'));

        return response()->json(['data' => $invoices->values()->all()]);
    }

    public function showInvoice(string $invoice, AccountingRequest $request, ApService $service): JsonResponse
    {
        $model = $service->findInvoice($request->accountingContext(), $invoice);

        return response()->json(['data' => $model]);
    }

    public function storeInvoice(AccountingRequest $request, ApService $service): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => 'required|string',
            'reference' => 'required|string|max:50',
            'supplier_reference' => 'nullable|string|max:100',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'subtotal_amount' => 'nullable|integer|min:0',
            'tax_amount' => 'nullable|integer|min:0',
            'total_amount' => 'required|integer|min:1',
            'currency' => 'nullable|string|size:3',
            'branch_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        $invoice = $service->createInvoice($request->accountingContext(), $data);

        return response()->json(['data' => $invoice], 201);
    }

    public function approveInvoice(string $invoice, AccountingRequest $request, ApService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:0']);
        $model = $service->approveInvoice($request->accountingContext(), $invoice, (int) $data['expected_version']);

        return response()->json(['data' => $model]);
    }

    public function payInvoice(string $invoice, AccountingRequest $request, ApService $service): JsonResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:50',
            'payment_date' => 'required|date',
            'amount' => 'required|integer|min:1',
            'method' => 'nullable|string|in:cash,bank_transfer,cheque',
            'currency' => 'nullable|string|size:3',
            'bank_account_id' => 'nullable|string',
            'branch_id' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);
        $payment = $service->payInvoice($request->accountingContext(), $invoice, $data);

        return response()->json(['data' => $payment], 201);
    }

    public function aging(AccountingRequest $request, ApService $service): JsonResponse
    {
        $report = $service->aging($request->accountingContext());

        return response()->json(['data' => $report]);
    }
}
