<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HrRequest;
use App\Modules\HR\Services\GeofenceService;
use Illuminate\Http\JsonResponse;

class GeofenceController extends Controller
{
    public function index(HrRequest $request, GeofenceService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->hrContext())->values()->all()]);
    }

    public function store(HrRequest $request, GeofenceService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'center_lat' => 'required|numeric|between:-90,90',
            'center_lng' => 'required|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:10000',
        ]);

        return response()->json(['data' => $service->create($request->hrContext(), $data)], 201);
    }

    public function update(string $geofence, HrRequest $request, GeofenceService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:100',
            'center_lat' => 'nullable|numeric|between:-90,90',
            'center_lng' => 'nullable|numeric|between:-180,180',
            'radius_meters' => 'nullable|integer|min:10|max:10000',
            'is_active' => 'nullable|boolean',
            'expected_version' => 'required|integer|min:0',
        ]);

        return response()->json(['data' => $service->update($request->hrContext(), $geofence, (int) $data['expected_version'], $data)]);
    }
}
