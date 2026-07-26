<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\InvoiceLine;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;

/**
 * Referential guards for menu deletions. A dish that has ever been ordered or
 * invoiced is financial history and must never disappear from those trails, so
 * it can only be retired (status change), not deleted. Ingredients are blocked
 * while any recipe version still references them.
 */
final class DishDeletionGuard
{
    /** Order states that still consume the dish and block deletion outright. */
    private const OPEN_ORDER_STATES = ['draft', 'placed', 'confirmed', 'preparing', 'ready'];

    /**
     * Assert a product may be deleted. Throws IN_USE when it is referenced by
     * an open order, by any invoice line, or by a recipe.
     */
    public function assertProductDeletable(MenuContext $context, string $productId): void
    {
        $variantIds = ProductVariant::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('product_id', $productId)->pluck('id')->all();

        if ($this->openOrderItemsExist($context, $productId, $variantIds)) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This dish is on an open order and cannot be deleted.',
                ['product_id' => $productId],
            );
        }

        if ($this->invoicedItemsExist($context, $productId, $variantIds)) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This dish appears on issued invoices; retire it instead of deleting it.',
                ['product_id' => $productId],
            );
        }

        if ($variantIds !== [] && $this->recipesExist($context, 'product_variant', $variantIds)) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This dish still has a recipe; delete the recipe first.',
                ['product_id' => $productId],
            );
        }
    }

    /**
     * Assert a single variant may be deleted: no open order lines, no invoiced
     * history, and no recipe of its own.
     */
    public function assertVariantDeletable(MenuContext $context, string $variantId): void
    {
        if ($this->openOrderItemsExist($context, null, [$variantId])) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This meal size is on an open order and cannot be deleted.',
                ['product_variant_id' => $variantId],
            );
        }

        if ($this->invoicedItemsExist($context, null, [$variantId])) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This meal size appears on issued invoices; retire it instead of deleting it.',
                ['product_variant_id' => $variantId],
            );
        }

        if ($this->recipesExist($context, 'product_variant', [$variantId])) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This meal size still has a recipe; delete the recipe first.',
                ['product_variant_id' => $variantId],
            );
        }
    }

    /** Assert an ingredient is not referenced by any recipe version. */
    public function assertStockItemDeletable(MenuContext $context, string $stockItemId): void
    {
        $this->assertComponentUnused($context, 'stock_item', $stockItemId,
            'This ingredient is used by a recipe and cannot be deleted.');
    }

    /**
     * Assert a semi-finished product is neither consumed by another recipe nor
     * still owning a recipe of its own.
     */
    public function assertSemiFinishedDeletable(MenuContext $context, string $semiFinishedId): void
    {
        $this->assertComponentUnused($context, 'semi_finished_product', $semiFinishedId,
            'This prepared item is used by a recipe and cannot be deleted.');

        if ($this->recipesExist($context, 'semi_finished_product', [$semiFinishedId])) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This prepared item still has a recipe; delete the recipe first.',
                ['semi_finished_product_id' => $semiFinishedId],
            );
        }
    }

    /**
     * Assert a category holds no products. Deleting one that still classifies
     * dishes would orphan them.
     */
    public function assertCategoryDeletable(MenuContext $context, string $categoryId): void
    {
        $inUse = Product::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('category_id', $categoryId)->exists();
        if ($inUse) {
            throw MenuException::conflict(
                MenuException::IN_USE,
                'This category still holds dishes and cannot be deleted.',
                ['category_id' => $categoryId],
            );
        }
    }

    /**
     * Whether a product/variant appears on any order line that is still open.
     *
     * @param  array<int, string>  $variantIds
     */
    private function openOrderItemsExist(MenuContext $context, ?string $productId, array $variantIds): bool
    {
        return OrderItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('state', 'active')
            ->where(fn ($q) => $q
                ->when($productId !== null, fn ($w) => $w->orWhere('product_id', $productId))
                ->when($variantIds !== [], fn ($w) => $w->orWhereIn('product_variant_id', $variantIds)))
            ->whereHas('order', fn ($q) => $q->whereIn('state', self::OPEN_ORDER_STATES))
            ->exists();
    }

    /**
     * Whether a product/variant has ever been billed. Invoice lines reference
     * the order item, so the check hops through it.
     *
     * @param  array<int, string>  $variantIds
     */
    private function invoicedItemsExist(MenuContext $context, ?string $productId, array $variantIds): bool
    {
        return InvoiceLine::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereHas('orderItem', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->when($productId !== null, fn ($s) => $s->orWhere('product_id', $productId))
                    ->when($variantIds !== [], fn ($s) => $s->orWhereIn('product_variant_id', $variantIds))))
            ->exists();
    }

    /**
     * Whether any recipe is owned by one of the given producibles.
     *
     * @param  array<int, string>  $ownerIds
     */
    private function recipesExist(MenuContext $context, string $ownerType, array $ownerIds): bool
    {
        return Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->exists();
    }

    /**
     * Throw when a stock item / semi-finished product is still referenced by a
     * recipe component on any version.
     */
    private function assertComponentUnused(MenuContext $context, string $componentType, string $componentId, string $message): void
    {
        $inUse = RecipeComponent::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('component_type', $componentType)->where('component_id', $componentId)->exists();
        if ($inUse) {
            throw MenuException::conflict(MenuException::IN_USE, $message, [
                'component_type' => $componentType,
                'component_id' => $componentId,
            ]);
        }
    }
}
