<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\IngredientRequest;
use App\Modules\Menu\Http\Requests\MenuRequest;
use App\Modules\Menu\Http\Resources\MenuResource;
use App\Modules\Menu\Services\IngredientService;
use Illuminate\Http\JsonResponse;

/**
 * Ingredients (raw stock items) and semi-finished products.
 */
class IngredientController extends Controller
{
    public function index(MenuRequest $request, IngredientService $service): JsonResponse
    {
        $rows = $service->stockItems($request->menuContext());

        return response()->json(['data' => $rows->map(MenuResource::stockItem(...))->values()->all()]);
    }

    public function store(IngredientRequest $request, IngredientService $service): JsonResponse
    {
        $item = $service->createStockItem($request->menuContext(), $request->validated());

        return response()->json(['data' => MenuResource::stockItem($item)], 201);
    }

    public function update(string $ingredient, IngredientRequest $request, IngredientService $service): JsonResponse
    {
        $item = $service->updateStockItem($request->menuContext(), $ingredient,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => MenuResource::stockItem($item)]);
    }

    public function semiFinished(MenuRequest $request, IngredientService $service): JsonResponse
    {
        $rows = $service->semiFinished($request->menuContext());

        return response()->json(['data' => $rows->map(fn ($s) => [
            'id' => $s->id, 'sku' => $s->sku, 'name' => $s->name, 'yield_unit' => $s->yield_unit,
            'yield_quantity' => $s->yield_quantity, 'calories_per_unit' => $s->calories_per_unit,
            'status' => $s->status, 'lock_version' => $s->lock_version,
        ])->values()->all()]);
    }

    public function storeSemiFinished(IngredientRequest $request, IngredientService $service): JsonResponse
    {
        $item = $service->createSemiFinished($request->menuContext(), $request->validated());

        return response()->json(['data' => [
            'id' => $item->id, 'sku' => $item->sku, 'name' => $item->name,
            'yield_unit' => $item->yield_unit, 'yield_quantity' => $item->yield_quantity,
        ]], 201);
    }
}
