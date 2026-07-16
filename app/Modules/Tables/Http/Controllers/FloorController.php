<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tables\Http\Requests\FloorRequest;
use App\Modules\Tables\Http\Resources\ReservationResource;
use App\Modules\Tables\Services\FloorDesignerService;
use Illuminate\Http\JsonResponse;

class FloorController extends Controller
{
    public function index(FloorRequest $request, FloorDesignerService $service): JsonResponse
    {
        $floors = $service->floors($request->reservationContext());

        return response()->json(['data' => $floors->map(fn ($floor) => ReservationResource::floor($floor))->values()]);
    }

    public function store(FloorRequest $request, FloorDesignerService $service): JsonResponse
    {
        $floor = $service->createFloor($request->reservationContext(), $request->validated());

        return response()->json(['data' => ReservationResource::floor($floor)], 201);
    }

    public function update(string $floor, FloorRequest $request, FloorDesignerService $service): JsonResponse
    {
        $model = $service->updateFloor($request->reservationContext(), $floor,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => ReservationResource::floor($model)]);
    }
}
