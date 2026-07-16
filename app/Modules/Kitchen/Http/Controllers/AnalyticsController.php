<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kitchen\Http\Requests\QueueRequest;
use App\Modules\Kitchen\Services\KitchenAnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * Kitchen analytics: headline KPIs (throughput, average prep time, on-time
 * rate, live load) and per-chef performance over a date window.
 */
class AnalyticsController extends Controller
{
    public function kpis(QueueRequest $request, KitchenAnalyticsService $service): JsonResponse
    {
        $data = $service->kpis($request->kitchenContext(),
            $request->validated('from'), $request->validated('to'), $request->validated('station_id'));

        return response()->json(['data' => $data]);
    }

    public function chefPerformance(QueueRequest $request, KitchenAnalyticsService $service): JsonResponse
    {
        $data = $service->chefPerformance($request->kitchenContext(),
            $request->validated('from'), $request->validated('to'));

        return response()->json(['data' => $data]);
    }
}
