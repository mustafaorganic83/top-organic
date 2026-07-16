<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\MediaRequest;
use App\Modules\Menu\Http\Requests\MenuRequest;
use App\Modules\Menu\Http\Resources\MenuResource;
use App\Modules\Menu\Services\MediaService;
use Illuminate\Http\JsonResponse;

/**
 * Image & video gallery endpoints for menu entities (product / variant /
 * category).
 */
class MediaController extends Controller
{
    public function index(MenuRequest $request, MediaService $service): JsonResponse
    {
        $request->validate([
            'entity_type' => ['required', 'string'],
            'entity_id' => ['required', 'ulid'],
        ]);
        $rows = $service->list($request->menuContext(),
            (string) $request->query('entity_type'), (string) $request->query('entity_id'));

        return response()->json(['data' => $rows->map(MenuResource::media(...))->values()->all()]);
    }

    public function store(MediaRequest $request, MediaService $service): JsonResponse
    {
        $asset = $service->create($request->menuContext(), $request->validated());

        return response()->json(['data' => MenuResource::media($asset)], 201);
    }

    public function update(string $media, MediaRequest $request, MediaService $service): JsonResponse
    {
        $asset = $service->update($request->menuContext(), $media,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => MenuResource::media($asset)]);
    }

    public function destroy(string $media, MenuRequest $request, MediaService $service): JsonResponse
    {
        $service->delete($request->menuContext(), $media);

        return response()->json(['data' => ['id' => $media, 'deleted' => true]]);
    }
}
