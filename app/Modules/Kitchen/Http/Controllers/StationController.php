<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kitchen\Http\Requests\QueueRequest;
use App\Modules\Kitchen\Http\Requests\StationRequest;
use App\Modules\Kitchen\Http\Resources\KitchenResource;
use App\Modules\Kitchen\Services\StationService;
use Illuminate\Http\JsonResponse;

class StationController extends Controller
{
    public function index(QueueRequest $request, StationService $service): JsonResponse
    {
        $stations = $service->list($request->kitchenContext(), ! $request->boolean('all'));

        return response()->json(['data' => $stations->map(KitchenResource::station(...))->values()->all()]);
    }

    public function store(StationRequest $request, StationService $service): JsonResponse
    {
        $station = $service->create($request->kitchenContext(), $request->validated());

        return response()->json(['data' => KitchenResource::station($station)], 201);
    }

    public function show(string $station, QueueRequest $request, StationService $service): JsonResponse
    {
        $model = $service->find($request->kitchenContext(), $station);

        return response()->json(['data' => KitchenResource::station($model)]);
    }

    public function update(string $station, StationRequest $request, StationService $service): JsonResponse
    {
        $model = $service->update($request->kitchenContext(), $station,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => KitchenResource::station($model)]);
    }
}
