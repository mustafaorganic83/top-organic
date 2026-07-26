<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductModifierGroup;
use App\Models\ProductVariant;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Services\Concerns\GuardsMenuWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menu authoring over the existing Sales catalog: categories, products, their
 * meal-size variants, and modifier groups/options (extras). All writes are
 * tenant-scoped with optimistic locking; nothing here duplicates catalog
 * tables — it enriches them with menu presentation and structure.
 */
final class MenuService
{
    use GuardsMenuWrites;

    public function __construct(private readonly DishDeletionGuard $deletionGuard) {}

    /** @return Collection<int, Category> */
    public function categories(MenuContext $context): Collection
    {
        return Category::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function createCategory(MenuContext $context, array $data): Category
    {
        $this->assertTenantCodeUnique(Category::class, $context->tenantId, 'code', (string) $data['code'], null);

        return Category::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(MenuContext $context, string $id, int $version, array $data): Category
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Category {
            $category = $this->find(Category::class, $context->tenantId, $id);
            $this->assertVersion($category->lock_version, $version);
            $category->fill(array_intersect_key($data, array_flip([
                'parent_id', 'name', 'description', 'image_url', 'sort_order', 'status',
            ])));
            $category->lock_version++;
            $category->save();

            return $category->refresh();
        }, 3);
    }

    /** @return Collection<int, Product> */
    public function products(MenuContext $context, ?string $categoryId): Collection
    {
        return Product::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->with(['variants', 'media'])
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    public function findProduct(MenuContext $context, string $id): Product
    {
        return Product::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->with(['variants', 'media', 'modifierGroups', 'allergenTags.allergen'])
            ->whereKey($id)->first()
            ?? throw MenuException::notFound('The product was not found.');
    }

    /** @param array<string, mixed> $data */
    public function createProduct(MenuContext $context, array $data): Product
    {
        $this->assertTenantCodeUnique(Product::class, $context->tenantId, 'sku', (string) $data['sku'], null);

        return Product::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'category_id' => $data['category_id'] ?? null,
            'tax_class_id' => $data['tax_class_id'] ?? null,
            'sku' => $data['sku'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'standard',
            'is_sellable' => $data['is_sellable'] ?? true,
            'is_meal' => $data['is_meal'] ?? false,
            'calories' => $data['calories'] ?? null,
            'nutrition_summary' => $data['nutrition_summary'] ?? null,
            'status' => $data['status'] ?? 'active',
            'sort_order' => $data['sort_order'] ?? 0,
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateProduct(MenuContext $context, string $id, int $version, array $data): Product
    {
        return DB::transaction(function () use ($context, $id, $version, $data): Product {
            $product = $this->find(Product::class, $context->tenantId, $id);
            $this->assertVersion($product->lock_version, $version);
            $product->fill(array_intersect_key($data, array_flip([
                'category_id', 'tax_class_id', 'name', 'description', 'type',
                'is_sellable', 'is_meal', 'calories', 'nutrition_summary', 'status', 'sort_order',
            ])));
            $product->lock_version++;
            $product->save();

            return $product->fresh(['variants', 'media']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createVariant(MenuContext $context, string $productId, array $data): ProductVariant
    {
        $product = $this->find(Product::class, $context->tenantId, $productId);
        $exists = ProductVariant::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('product_id', $product->id)->where('code', $data['code'])->exists();
        if ($exists) {
            throw MenuException::conflict(MenuException::IN_USE,
                'A variant with this code already exists for the product.');
        }

        return ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'product_id' => $product->id,
            'code' => $data['code'],
            'name' => $data['name'] ?? null,
            'meal_size' => $data['meal_size'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'calories' => $data['calories'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateVariant(MenuContext $context, string $productId, string $variantId, int $version, array $data): ProductVariant
    {
        return DB::transaction(function () use ($context, $productId, $variantId, $version, $data): ProductVariant {
            $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('product_id', $productId)->whereKey($variantId)->lockForUpdate()->first()
                ?? throw MenuException::notFound('The variant was not found.');
            $this->assertVersion($variant->lock_version, $version);
            $variant->fill(array_intersect_key($data, array_flip([
                'name', 'meal_size', 'barcode', 'calories', 'sort_order', 'status',
            ])));
            $variant->lock_version++;
            $variant->save();

            return $variant->refresh();
        }, 3);
    }

    /**
     * Soft-delete a dish once the referential guards pass. Dishes with billed
     * history are never deletable — retire them by setting status instead.
     */
    public function deleteProduct(MenuContext $context, string $id, int $version): void
    {
        DB::transaction(function () use ($context, $id, $version): void {
            $product = $this->find(Product::class, $context->tenantId, $id);
            $this->assertVersion($product->lock_version, $version);
            $this->deletionGuard->assertProductDeletable($context, $product->id);
            $product->delete();
        }, 3);
    }

    /** Soft-delete a meal-size variant once its referential guards pass. */
    public function deleteVariant(MenuContext $context, string $productId, string $variantId, int $version): void
    {
        DB::transaction(function () use ($context, $productId, $variantId, $version): void {
            $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('product_id', $productId)->whereKey($variantId)->lockForUpdate()->first()
                ?? throw MenuException::notFound('The variant was not found.');
            $this->assertVersion($variant->lock_version, $version);
            $this->deletionGuard->assertVariantDeletable($context, $variant->id);
            $variant->delete();
        }, 3);
    }

    /** Soft-delete an empty category. */
    public function deleteCategory(MenuContext $context, string $id, int $version): void
    {
        DB::transaction(function () use ($context, $id, $version): void {
            $category = $this->find(Category::class, $context->tenantId, $id);
            $this->assertVersion($category->lock_version, $version);
            $this->deletionGuard->assertCategoryDeletable($context, $category->id);
            $category->delete();
        }, 3);
    }

    /** @return Collection<int, ModifierGroup> */
    public function modifierGroups(MenuContext $context): Collection
    {
        return ModifierGroup::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->with('options')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function createModifierGroup(MenuContext $context, array $data): ModifierGroup
    {
        $this->assertTenantCodeUnique(ModifierGroup::class, $context->tenantId, 'code', (string) $data['code'], null);

        return ModifierGroup::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'min_selections' => $data['min_selections'] ?? 0,
            'max_selections' => $data['max_selections'] ?? null,
            'is_required' => $data['is_required'] ?? false,
            'status' => $data['status'] ?? 'active',
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function createModifierOption(MenuContext $context, string $groupId, array $data): ModifierOption
    {
        $group = $this->find(ModifierGroup::class, $context->tenantId, $groupId);

        return ModifierOption::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'modifier_group_id' => $group->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'surcharge_amount' => $data['surcharge_amount'] ?? 0,
            'currency' => $data['currency'],
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function attachModifierGroup(MenuContext $context, string $productId, array $data): ProductModifierGroup
    {
        $product = $this->find(Product::class, $context->tenantId, $productId);
        $group = $this->find(ModifierGroup::class, $context->tenantId, (string) $data['modifier_group_id']);
        $exists = ProductModifierGroup::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $data['product_variant_id'] ?? null)
            ->where('modifier_group_id', $group->id)->exists();
        if ($exists) {
            throw MenuException::conflict(MenuException::IN_USE,
                'This modifier group is already attached to the product.');
        }

        return ProductModifierGroup::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'product_id' => $product->id,
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'modifier_group_id' => $group->id,
            'sort_order' => $data['sort_order'] ?? 0,
            'min_selections' => $data['min_selections'] ?? null,
            'max_selections' => $data['max_selections'] ?? null,
        ]);
    }

    /**
     * Resolve a model row within the tenant or throw a NOT_FOUND.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function find(string $model, string $tenantId, string $id)
    {
        return $model::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereKey($id)->lockForUpdate()->first()
            ?? throw MenuException::notFound('The requested record was not found.');
    }
}
