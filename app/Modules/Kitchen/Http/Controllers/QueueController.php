<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kitchen\Http\Requests\QueueRequest;
use App\Modules\Kitchen\Http\Resources\KitchenResource;
use App\Modules\Kitchen\Services\KitchenQueueService;
use Illuminate\Http\JsonResponse;

/**
 * The kitchen board read side: the full phase board for a screen, a single
 * phase queue (preparation / cooking / ready / served), and a single ticket
 * with its live timer.
 */
class QueueController extends Controller
{
    public function board(QueueRequest $request, KitchenQueueService $service): JsonResponse
    {
        $board = $service->board($request->kitchenContext(), $request->validated('station_id'));

        return response()->json(['data' => $board]);
    }

    public function phase(string $phase, QueueRequest $request, KitchenQueueService $service): JsonResponse
    {
        $queue = $service->queue($request->kitchenContext(), $phase, $request->validated('station_id'));

        return response()->json(['data' => $queue->all()]);
    }

    public function show(string $ticket, QueueRequest $request, KitchenQueueService $service): JsonResponse
    {
        $model = $service->find($request->kitchenContext(), $ticket);

        return response()->json(['data' => KitchenResource::ticket($model)]);
    }
}
