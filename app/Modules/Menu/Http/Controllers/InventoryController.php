<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\InventoryRequest;
use App\Modules\Menu\Http\Requests\MenuRequest;
use App\Modules\Menu\Http\Resources\RecipeResource;
use App\Modules\Menu\Services\InventoryService;
use Illuminate\Http\JsonResponse;

/**
 * Branch inventory: on-hand levels, the movement ledger, manual adjustments,
 * and explicit consumption of a sold variant (same engine the order-completion
 * hook drives).
 */
class InventoryController extends Controller
{
    public function levels(MenuRequest $request, InventoryService $service): JsonResponse
    {
        $rows = $service->levels($request->menuContext());

        return response()->json(['data' => $rows->map(RecipeResource::stockLevel(...))->values()->all()]);
    }

    public function movements(MenuRequest $request, InventoryService $service): JsonResponse
    {
        $rows = $service->movements($request->menuContext(),
            $request->query('stockable_type'), $request->query('stockable_id'));

        return response()->json(['data' => $rows->map(RecipeResource::movement(...))->values()->all()]);
    }

    public function adjust(InventoryRequest $request, InventoryService $service): JsonResponse
    {
        $movement = $service->adjust($request->menuContext(), $request->validated());

        return response()->json(['data' => RecipeResource::movement($movement)], 201);
    }

    public function consume(InventoryRequest $request, InventoryService $service): JsonResponse
    {
        $data = $request->validated();
        $movements = $service->consumeForVariant(
            $request->menuContext(),
            (string) $data['product_variant_id'],
            (float) $data['quantity'],
            (string) $data['client_operation_id'],
            $data['reference_type'] ?? null,
            $data['reference_id'] ?? null,
        );

        return response()->json(['data' => array_map(RecipeResource::movement(...), $movements)], 201);
    }
}
