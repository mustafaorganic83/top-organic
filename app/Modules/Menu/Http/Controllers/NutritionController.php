<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\MenuRequest;
use App\Modules\Menu\Http\Requests\NutritionRequest;
use App\Modules\Menu\Services\NutritionService;
use Illuminate\Http\JsonResponse;

/**
 * Allergen catalog, allergen tagging, and the nutrition/allergen roll-up for a
 * product.
 */
class NutritionController extends Controller
{
    public function allergens(MenuRequest $request, NutritionService $service): JsonResponse
    {
        $rows = $service->allergens($request->menuContext());

        return response()->json(['data' => $rows->map(fn ($a) => [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'icon' => $a->icon, 'status' => $a->status,
        ])->values()->all()]);
    }

    public function storeAllergen(NutritionRequest $request, NutritionService $service): JsonResponse
    {
        $allergen = $service->createAllergen($request->menuContext(), $request->validated());

        return response()->json(['data' => [
            'id' => $allergen->id, 'code' => $allergen->code, 'name' => $allergen->name, 'icon' => $allergen->icon,
        ]], 201);
    }

    public function tag(NutritionRequest $request, NutritionService $service): JsonResponse
    {
        $tag = $service->tag($request->menuContext(), $request->validated());

        return response()->json(['data' => [
            'id' => $tag->id, 'allergen_id' => $tag->allergen_id,
            'entity_type' => $tag->entity_type, 'entity_id' => $tag->entity_id, 'is_traces' => $tag->is_traces,
        ]], 201);
    }

    public function productNutrition(string $product, MenuRequest $request, NutritionService $service): JsonResponse
    {
        return response()->json(['data' => $service->productNutrition($request->menuContext(), $product)]);
    }
}
