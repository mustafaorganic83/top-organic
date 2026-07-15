<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\PrintRequest;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Modules\Sales\Services\PrintService;
use Illuminate\Http\JsonResponse;

class PrintController extends Controller
{
    public function store(PrintRequest $r, PrintService $s): JsonResponse
    {
        $job = $s->enqueue($r->salesContext(), $r->validated('payload_type'), $r->validated('document_id'),
            $r->validated('idempotency_key'), $r->validated('printer_id'), $r->validated('client_operation_id'));

        return response()->json(['data' => SalesResource::printJob($job)], 201);
    }

    public function show(string $job, PrintRequest $r): JsonResponse
    {
        $c = $r->salesContext();
        $model = PrintJob::withoutGlobalScopes()->where('tenant_id', $c->tenantId)->where('branch_id', $c->branchId)->find($job)
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The print job was not found.');

        return response()->json(['data' => SalesResource::printJob($model)]);
    }

    public function claim(PrintRequest $r, PrintService $s): JsonResponse
    {
        $job = $s->claim($r->salesContext());

        return response()->json(['data' => $job === null ? null : SalesResource::printJob($job)]);
    }

    public function complete(string $job, PrintRequest $r, PrintService $s): JsonResponse
    {
        return response()->json(['data' => SalesResource::printJob($s->complete($r->salesContext(), $job, $r->integer('expected_version')))]);
    }

    public function fail(string $job, PrintRequest $r, PrintService $s): JsonResponse
    {
        return response()->json(['data' => SalesResource::printJob($s->fail($r->salesContext(), $job,
            $r->integer('expected_version'), $r->validated('error_code'), $r->validated('error_message')))]);
    }

    public function retry(string $job, PrintRequest $r, PrintService $s): JsonResponse
    {
        return response()->json(['data' => SalesResource::printJob($s->retry($r->salesContext(), $job, $r->integer('expected_version')))]);
    }
}
