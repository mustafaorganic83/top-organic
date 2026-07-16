<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\SyncConflictRequest;
use App\Modules\Sales\Http\Requests\SyncCursorRequest;
use App\Modules\Sales\Http\Requests\SyncPullRequest;
use App\Modules\Sales\Http\Requests\SyncPushRequest;
use App\Modules\Sales\Http\Resources\SyncResource;
use App\Modules\Sales\Services\OfflineSyncService;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    public function push(SyncPushRequest $request, OfflineSyncService $service): JsonResponse
    {
        $result = $service->push($request->salesContext(), $request->validated('client_batch_id'), $request->operations());

        return response()->json(['data' => SyncResource::push($result)]);
    }

    public function pull(SyncPullRequest $request, OfflineSyncService $service): JsonResponse
    {
        $feed = $service->pull($request->salesContext(), $request->stream(), $request->cursor(), $request->limit());

        return response()->json(['data' => $feed]);
    }

    public function acknowledge(SyncCursorRequest $request, OfflineSyncService $service): JsonResponse
    {
        $cursor = $service->acknowledgePull($request->salesContext(), $request->stream(),
            $request->sequence(), $request->resync());

        return response()->json(['data' => SyncResource::cursor($cursor)]);
    }

    public function conflicts(SyncConflictRequest $request, OfflineSyncService $service): JsonResponse
    {
        $page = $service->conflicts($request->salesContext(), $request->perPage());

        return response()->json(SyncResource::conflicts($page));
    }

    public function resolveConflict(string $conflict, SyncConflictRequest $request, OfflineSyncService $service): JsonResponse
    {
        $model = $service->resolveConflict($request->salesContext(), $conflict,
            (string) $request->validated('resolution', 'discard'), $request->validated('reason'));

        return response()->json(['data' => SyncResource::conflict($model)]);
    }
}
