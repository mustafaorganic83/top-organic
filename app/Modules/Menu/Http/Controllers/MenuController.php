<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\CategoryRequest;
use App\Modules\Menu\Http\Requests\DeletionRequest;
use App\Modules\Menu\Http\Requests\MenuRequest;
use App\Modules\Menu\Http\Requests\ModifierRequest;
use App\Modules\Menu\Http\Requests\ProductRequest;
use App\Modules\Menu\Http\Requests\VariantRequest;
use App\Modules\Menu\Http\Resources\MenuResource;
use App\Modules\Menu\Services\MenuService;
use Illuminate\Http\JsonResponse;

/**
 * Menu authoring endpoints: categories, products, meal-size variants, and
 * modifier groups/options (extras), all over the existing Sales catalog.
 */
class MenuController extends Controller
{
    public function categories(MenuRequest $request, MenuService $service): JsonResponse
    {
        $rows = $service->categories($request->menuContext());

        return response()->json(['data' => $rows->map(MenuResource::category(...))->values()->all()]);
    }

    public function storeCategory(CategoryRequest $request, MenuService $service): JsonResponse
    {
        $category = $service->createCategory($request->menuContext(), $request->validated());

        return response()->json(['data' => MenuResource::category($category)], 201);
    }

    public function updateCategory(string $category, CategoryRequest $request, MenuService $service): JsonResponse
    {
        $model = $service->updateCategory($request->menuContext(), $category,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => MenuResource::category($model)]);
    }

    public function destroyCategory(string $category, DeletionRequest $request, MenuService $service): JsonResponse
    {
        $service->deleteCategory($request->menuContext(), $category,
            (int) $request->validated('expected_version'));

        return response()->json(status: 204);
    }

    public function products(MenuRequest $request, MenuService $service): JsonResponse
    {
        $rows = $service->products($request->menuContext(), $request->query('category_id'));

        return response()->json(['data' => $rows->map(MenuResource::product(...))->values()->all()]);
    }

    public function showProduct(string $product, MenuRequest $request, MenuService $service): JsonResponse
    {
        $model = $service->findProduct($request->menuContext(), $product);

        return response()->json(['data' => MenuResource::product($model)]);
    }

    public function storeProduct(ProductRequest $request, MenuService $service): JsonResponse
    {
        $product = $service->createProduct($request->menuContext(), $request->validated());

        return response()->json(['data' => MenuResource::product($product)], 201);
    }

    public function updateProduct(string $product, ProductRequest $request, MenuService $service): JsonResponse
    {
        $model = $service->updateProduct($request->menuContext(), $product,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => MenuResource::product($model)]);
    }

    public function destroyProduct(string $product, DeletionRequest $request, MenuService $service): JsonResponse
    {
        $service->deleteProduct($request->menuContext(), $product,
            (int) $request->validated('expected_version'));

        return response()->json(status: 204);
    }

    public function storeVariant(string $product, VariantRequest $request, MenuService $service): JsonResponse
    {
        $variant = $service->createVariant($request->menuContext(), $product, $request->validated());

        return response()->json(['data' => MenuResource::variant($variant)], 201);
    }

    public function updateVariant(string $product, string $variant, VariantRequest $request, MenuService $service): JsonResponse
    {
        $model = $service->updateVariant($request->menuContext(), $product, $variant,
            (int) $request->validated('expected_version'), $request->validated());

        return response()->json(['data' => MenuResource::variant($model)]);
    }

    public function destroyVariant(string $product, string $variant, DeletionRequest $request, MenuService $service): JsonResponse
    {
        $service->deleteVariant($request->menuContext(), $product, $variant,
            (int) $request->validated('expected_version'));

        return response()->json(status: 204);
    }

    public function modifierGroups(MenuRequest $request, MenuService $service): JsonResponse
    {
        $rows = $service->modifierGroups($request->menuContext());

        return response()->json(['data' => $rows->map(MenuResource::modifierGroup(...))->values()->all()]);
    }

    public function storeModifierGroup(ModifierRequest $request, MenuService $service): JsonResponse
    {
        $group = $service->createModifierGroup($request->menuContext(), $request->validated());

        return response()->json(['data' => MenuResource::modifierGroup($group)], 201);
    }

    public function storeModifierOption(string $group, ModifierRequest $request, MenuService $service): JsonResponse
    {
        $option = $service->createModifierOption($request->menuContext(), $group, $request->validated());

        return response()->json(['data' => [
            'id' => $option->id, 'code' => $option->code, 'name' => $option->name,
            'surcharge_amount' => $option->surcharge_amount, 'currency' => $option->currency,
        ]], 201);
    }

    public function attachModifierGroup(string $product, ModifierRequest $request, MenuService $service): JsonResponse
    {
        $link = $service->attachModifierGroup($request->menuContext(), $product, $request->validated());

        return response()->json(['data' => [
            'id' => $link->id, 'product_id' => $link->product_id,
            'product_variant_id' => $link->product_variant_id, 'modifier_group_id' => $link->modifier_group_id,
        ]], 201);
    }
}
