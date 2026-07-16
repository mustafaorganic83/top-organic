<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tables\Http\Requests\ReservationListRequest;
use App\Modules\Tables\Http\Resources\ReservationResource;
use App\Modules\Tables\Services\FloorDesignerService;
use App\Modules\Tables\Services\ReservationService;
use App\Modules\Tables\Services\TableManagementService;
use App\Modules\Tables\Services\WaitlistService;
use Illuminate\Http\JsonResponse;

/**
 * Aggregated read model for the tablet reception screen: the live floor map
 * with per-table occupancy, today's upcoming reservations, and the active
 * waiting list — served in a single call so the host station stays in sync.
 */
class ReceptionController extends Controller
{
    public function overview(
        ReservationListRequest $request,
        FloorDesignerService $floors,
        TableManagementService $tables,
        ReservationService $reservations,
        WaitlistService $waitlist,
    ): JsonResponse {
        $context = $request->reservationContext();
        $upcoming = $reservations->list($context, ['state' => 'confirmed', 'date' => now()->toDateString()], 100);

        return response()->json(['data' => [
            'floors' => $floors->floors($context)->map(fn ($f) => ReservationResource::floor($f))->values(),
            'tables' => $tables->tables($context)->map(fn ($t) => ReservationResource::table($t))->values(),
            'upcoming_reservations' => collect($upcoming->items())
                ->map(fn ($r) => ReservationResource::reservation($r))->values(),
            'waitlist' => $waitlist->active($context)->map(fn ($e) => ReservationResource::waitlist($e))->values(),
            'server_time' => now()->toISOString(),
        ]]);
    }
}
