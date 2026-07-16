<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\ArService;
use Illuminate\Http\JsonResponse;

class ArController extends Controller
{
    public function invoices(AccountingRequest $request, ArService $service): JsonResponse
    {
        $invoices = $service->invoices($request->accountingContext(),
            $request->query('customer_id'), $request->query('status'));

        return response()->json(['data' => $invoices->values()->all()]);
    }

    public function showInvoice(string $invoice, AccountingRequest $request, ArService $service): JsonResponse
    {
        $model = $service->findInvoice($request->accountingContext(), $invoice);

        return response()->json(['data' => $model]);
    }

    public function storeInvoice(AccountingRequest $request, ArService $service): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'nullable|string',
            'order_invoice_id' => 'nullable|string',
            'reference' => 'required|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'total_amount' => 'required|integer|min:1',
            'currency' => 'nullable|string|size:3',
            'branch_id' => 'nullable|string',
        ]);
        $invoice = $service->createInvoice($request->accountingContext(), $data);

        return response()->json(['data' => $invoice], 201);
    }

    public function aging(AccountingRequest $request, ArService $service): JsonResponse
    {
        $report = $service->aging($request->accountingContext());

        return response()->json(['data' => $report]);
    }
}
