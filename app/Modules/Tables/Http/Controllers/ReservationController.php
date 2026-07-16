<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tables\Http\Requests\ReservationActionRequest;
use App\Modules\Tables\Http\Requests\ReservationCreateRequest;
use App\Modules\Tables\Http\Requests\ReservationListRequest;
use App\Modules\Tables\Http\Resources\ReservationResource;
use App\Modules\Tables\Services\ReservationService;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function index(ReservationListRequest $request, ReservationService $service): JsonResponse
    {
        $page = $service->list($request->reservationContext(), $request->filters(), $request->perPage());

        return $this->paginated($page);
    }

    public function store(ReservationCreateRequest $request, ReservationService $service): JsonResponse
    {
        $reservation = $service->create($request->reservationContext(), $request->validated());

        return response()->json(['data' => ReservationResource::reservation($reservation)], 201);
    }

    public function show(string $reservation, ReservationListRequest $request, ReservationService $service): JsonResponse
    {
        $model = $service->find($request->reservationContext(), $reservation);

        return response()->json(['data' => ReservationResource::reservation($model)]);
    }

    public function confirm(string $reservation, ReservationActionRequest $request, ReservationService $service): JsonResponse
    {
        $model = $service->confirm($request->reservationContext(), $reservation,
            (int) $request->validated('expected_version'), $request->validated('confirmation_channel'));

        return response()->json(['data' => ReservationResource::reservation($model)]);
    }

    public function assign(string $reservation, ReservationActionRequest $request, ReservationService $service): JsonResponse
    {
        $model = $service->assignTable($request->reservationContext(), $reservation,
            (string) $request->validated('table_id'), (int) $request->validated('expected_version'));

        return response()->json(['data' => ReservationResource::reservation($model)]);
    }

    public function seat(string $reservation, ReservationActionRequest $request, ReservationService $service): JsonResponse
    {
        $model = $service->seat($request->reservationContext(), $reservation, (int) $request->validated('expected_version'));

        return response()->json(['data' => ReservationResource::reservation($model)]);
    }

    public function complete(string $reservation, ReservationActionRequest $request, ReservationService $service): JsonResponse
    {
        $model = $service->complete($request->reservationContext(), $reservation, (int) $request->validated('expected_version'));

        return response()->json(['data' => ReservationResource::reservation($model)]);
    }

    public function cancel(string $reservation, ReservationActionRequest $request, ReservationService $service): JsonResponse
    {
        $model = $service->cancel($request->reservationContext(), $reservation,
            (int) $request->validated('expected_version'), $request->validated('reason'));

        return response()->json(['data' => ReservationResource::reservation($model)]);
    }

    public function noShow(string $reservation, ReservationActionRequest $request, ReservationService $service): JsonResponse
    {
        $model = $service->markNoShow($request->reservationContext(), $reservation, (int) $request->validated('expected_version'));

        return response()->json(['data' => ReservationResource::reservation($model)]);
    }

    public function customerHistory(string $customer, ReservationListRequest $request, ReservationService $service): JsonResponse
    {
        $page = $service->customerHistory($request->reservationContext(), $customer, $request->perPage());

        return $this->paginated($page);
    }

    private function paginated($page): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => ReservationResource::reservation($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
