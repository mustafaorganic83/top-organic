<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\Requests\AccountingRequest;
use App\Modules\Accounting\Services\SupplierService;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    public function index(AccountingRequest $request, SupplierService $service): JsonResponse
    {
        $suppliers = $service->list($request->accountingContext(), $request->query('status'));

        return response()->json(['data' => $suppliers->values()->all()]);
    }

    public function show(string $supplier, AccountingRequest $request, SupplierService $service): JsonResponse
    {
        $model = $service->find($request->accountingContext(), $supplier);

        return response()->json(['data' => $model]);
    }

    public function store(AccountingRequest $request, SupplierService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:200',
            'tax_number' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:200',
            'address' => 'nullable|string',
            'ap_account_id' => 'nullable|string',
            'payment_terms' => 'nullable|string|max:50',
            'currency' => 'nullable|string|size:3',
            'status' => 'nullable|string|in:active,inactive',
        ]);
        $supplier = $service->create($request->accountingContext(), $data);

        return response()->json(['data' => $supplier], 201);
    }

    public function update(string $supplier, AccountingRequest $request, SupplierService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:200',
            'tax_number' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:200',
            'address' => 'nullable|string',
            'ap_account_id' => 'nullable|string',
            'payment_terms' => 'nullable|string|max:50',
            'currency' => 'nullable|string|size:3',
            'status' => 'nullable|string|in:active,inactive',
            'expected_version' => 'required|integer|min:0',
        ]);
        $model = $service->update($request->accountingContext(), $supplier,
            (int) $data['expected_version'], $data);

        return response()->json(['data' => $model]);
    }
}
