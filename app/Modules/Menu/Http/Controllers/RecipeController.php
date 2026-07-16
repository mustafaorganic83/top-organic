<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\MenuRequest;
use App\Modules\Menu\Http\Requests\RecipeRequest;
use App\Modules\Menu\Http\Resources\RecipeResource;
use App\Modules\Menu\Services\RecipeService;
use Illuminate\Http\JsonResponse;

/**
 * Recipe builder & version lifecycle: create a recipe, draft its BOM, publish
 * (freezes a costed + nutrition snapshot), activate, and read the costing.
 */
class RecipeController extends Controller
{
    public function index(MenuRequest $request, RecipeService $service): JsonResponse
    {
        $rows = $service->list($request->menuContext());

        return response()->json(['data' => $rows->map(RecipeResource::recipe(...))->values()->all()]);
    }

    public function show(string $recipe, MenuRequest $request, RecipeService $service): JsonResponse
    {
        $model = $service->find($request->menuContext(), $recipe);

        return response()->json(['data' => RecipeResource::recipe($model)]);
    }

    public function store(RecipeRequest $request, RecipeService $service): JsonResponse
    {
        $recipe = $service->create($request->menuContext(), $request->validated());

        return response()->json(['data' => RecipeResource::recipe($recipe)], 201);
    }

    public function draftVersion(string $recipe, RecipeRequest $request, RecipeService $service): JsonResponse
    {
        $version = $service->draftVersion($request->menuContext(), $recipe, $request->validated());

        return response()->json(['data' => RecipeResource::version($version)], 201);
    }

    public function publishVersion(string $recipe, string $version, MenuRequest $request, RecipeService $service): JsonResponse
    {
        $model = $service->publishVersion($request->menuContext(), $recipe, $version);

        return response()->json(['data' => RecipeResource::version($model)]);
    }

    public function activateVersion(string $recipe, string $version, MenuRequest $request, RecipeService $service): JsonResponse
    {
        $model = $service->activateVersion($request->menuContext(), $recipe, $version);

        return response()->json(['data' => RecipeResource::version($model)]);
    }

    public function cost(string $recipe, MenuRequest $request, RecipeService $service): JsonResponse
    {
        return response()->json(['data' => $service->costReport($request->menuContext(), $recipe)]);
    }
}
