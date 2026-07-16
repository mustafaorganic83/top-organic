<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tables\Http\Requests\WaitlistRequest;
use App\Modules\Tables\Http\Resources\ReservationResource;
use App\Modules\Tables\Services\WaitlistService;
use Illuminate\Http\JsonResponse;

class WaitlistController extends Controller
{
    public function index(WaitlistRequest $request, WaitlistService $service): JsonResponse
    {
        $entries = $service->active($request->reservationContext());

        return response()->json(['data' => $entries->map(fn ($e) => ReservationResource::waitlist($e))->values()]);
    }

    public function store(WaitlistRequest $request, WaitlistService $service): JsonResponse
    {
        $entry = $service->join($request->reservationContext(), $request->validated());

        return response()->json(['data' => ReservationResource::waitlist($entry)], 201);
    }

    public function notify(string $entry, WaitlistRequest $request, WaitlistService $service): JsonResponse
    {
        $model = $service->notify($request->reservationContext(), $entry, (int) $request->validated('expected_version'));

        return response()->json(['data' => ReservationResource::waitlist($model)]);
    }

    public function seat(string $entry, WaitlistRequest $request, WaitlistService $service): JsonResponse
    {
        $model = $service->seat($request->reservationContext(), $entry, (int) $request->validated('expected_version'));

        return response()->json(['data' => ReservationResource::waitlist($model)]);
    }

    public function cancel(string $entry, WaitlistRequest $request, WaitlistService $service): JsonResponse
    {
        $model = $service->cancel($request->reservationContext(), $entry,
            (int) $request->validated('expected_version'), $request->validated('reason'));

        return response()->json(['data' => ReservationResource::waitlist($model)]);
    }
}
