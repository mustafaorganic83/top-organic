<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tables\Http\Requests\RoomRequest;
use App\Modules\Tables\Http\Resources\ReservationResource;
use App\Modules\Tables\Services\FloorDesignerService;
use Illuminate\Http\JsonResponse;

class RoomController extends Controller
{
    public function store(RoomRequest $request, FloorDesignerService $service): JsonResponse
    {
        $room = $service->createRoom($request->reservationContext(), $request->validated());

        return response()->json(['data' => ReservationResource::room($room)], 201);
    }
}
