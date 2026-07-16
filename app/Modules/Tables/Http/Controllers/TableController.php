<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tables\Http\Requests\TableRequest;
use App\Modules\Tables\Http\Resources\ReservationResource;
use App\Modules\Tables\Services\TableManagementService;
use Illuminate\Http\JsonResponse;

class TableController extends Controller
{
    public function index(TableRequest $request, TableManagementService $service): JsonResponse
    {
        $tables = $service->tables($request->reservationContext(),
            $request->query('occupancy_status'), $request->query('area'));

        return response()->json(['data' => $tables->map(fn ($t) => ReservationResource::table($t))->values()]);
    }

    public function store(TableRequest $request, TableManagementService $service): JsonResponse
    {
        $table = $service->createTable($request->reservationContext(), $request->validated());

        return response()->json(['data' => ReservationResource::table($table)], 201);
    }

    public function update(string $table, TableRequest $request, TableManagementService $service): JsonResponse
    {
        $model = $service->updateTable($request->reservationContext(), $table,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => ReservationResource::table($model)]);
    }

    public function occupancy(string $table, TableRequest $request, TableManagementService $service): JsonResponse
    {
        $model = $service->changeOccupancy($request->reservationContext(), $table,
            (string) $request->validated('occupancy_status'), (int) $request->validated('expected_version'));

        return response()->json(['data' => ReservationResource::table($model)]);
    }
}
