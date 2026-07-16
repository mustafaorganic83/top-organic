<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\DocumentService;
use Illuminate\Http\JsonResponse;

class DocumentController extends Controller
{
    public function index(string $employee, HrRequest $request, DocumentService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->hrContext(), $employee)->values()->all()]);
    }

    public function store(string $employee, HrRequest $request, DocumentService $service): JsonResponse
    {
        $data = $request->validate([
            'document_type' => 'required|string|max:64',
            'title' => 'required|string|max:200',
            'file_path' => 'nullable|string|max:512',
            'file_name' => 'nullable|string|max:256',
            'expiry_date' => 'nullable|date',
        ]);
        $data['employee_id'] = $employee;

        return response()->json(['data' => $service->create($request->hrContext(), $data)], 201);
    }

    public function destroy(string $employee, string $document, HrRequest $request, DocumentService $service): JsonResponse
    {
        $service->delete($request->hrContext(), $document);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
