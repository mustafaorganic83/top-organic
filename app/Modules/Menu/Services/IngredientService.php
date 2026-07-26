<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\SemiFinishedProduct;
use App\Models\StockItem;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Services\Concerns\GuardsMenuWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for ingredients (raw stock items) and semi-finished products. Both are
 * costable and stockable; semi-finished products additionally own their own
 * recipe, letting the BOM nest (a sauce made from ingredients, then used in a
 * dish).
 */
final class IngredientService
{
    use GuardsMenuWrites;

    public function __construct(private readonly DishDeletionGuard $deletionGuard) {}

    /** @return Collection<int, StockItem> */
    public function stockItems(MenuContext $context): Collection
    {
        return StockItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function createStockItem(MenuContext $context, array $data): StockItem
    {
        $this->assertTenantCodeUnique(StockItem::class, $context->tenantId, 'sku', (string) $data['sku'], null);

        return StockItem::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'sku' => $data['sku'],
            'name' => $data['name'],
            'kind' => $data['kind'] ?? 'ingredient',
            'stock_unit' => $data['stock_unit'],
            'unit_cost_amount' => $data['unit_cost_amount'] ?? 0,
            'currency' => $data['currency'],
            'default_waste_bps' => $data['default_waste_bps'] ?? 0,
            'calories_per_unit' => $data['calories_per_unit'] ?? null,
            'nutrition' => $data['nutrition'] ?? null,
            'status' => $data['status'] ?? 'active',
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateStockItem(MenuContext $context, string $id, int $version, array $data): StockItem
    {
        return DB::transaction(function () use ($context, $id, $version, $data): StockItem {
            $item = StockItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw MenuException::notFound('The ingredient was not found.');
            $this->assertVersion($item->lock_version, $version);
            $item->fill(array_intersect_key($data, array_flip([
                'name', 'kind', 'stock_unit', 'unit_cost_amount', 'currency',
                'default_waste_bps', 'calories_per_unit', 'nutrition', 'status',
            ])));
            $item->lock_version++;
            $item->save();

            return $item->refresh();
        }, 3);
    }

    /** Soft-delete an ingredient that no recipe version references. */
    public function deleteStockItem(MenuContext $context, string $id, int $version): void
    {
        DB::transaction(function () use ($context, $id, $version): void {
            $item = StockItem::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw MenuException::notFound('The ingredient was not found.');
            $this->assertVersion($item->lock_version, $version);
            $this->deletionGuard->assertStockItemDeletable($context, $item->id);
            $item->delete();
        }, 3);
    }

    /** @return Collection<int, SemiFinishedProduct> */
    public function semiFinished(MenuContext $context): Collection
    {
        return SemiFinishedProduct::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function createSemiFinished(MenuContext $context, array $data): SemiFinishedProduct
    {
        $this->assertTenantCodeUnique(SemiFinishedProduct::class, $context->tenantId, 'sku', (string) $data['sku'], null);

        return SemiFinishedProduct::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'sku' => $data['sku'],
            'name' => $data['name'],
            'yield_unit' => $data['yield_unit'],
            'yield_quantity' => $data['yield_quantity'] ?? 1,
            'calories_per_unit' => $data['calories_per_unit'] ?? null,
            'nutrition' => $data['nutrition'] ?? null,
            'status' => $data['status'] ?? 'active',
            'lock_version' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateSemiFinished(MenuContext $context, string $id, int $version, array $data): SemiFinishedProduct
    {
        return DB::transaction(function () use ($context, $id, $version, $data): SemiFinishedProduct {
            $item = SemiFinishedProduct::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw MenuException::notFound('The prepared item was not found.');
            $this->assertVersion($item->lock_version, $version);
            $item->fill(array_intersect_key($data, array_flip([
                'name', 'yield_unit', 'yield_quantity', 'calories_per_unit', 'nutrition', 'status',
            ])));
            $item->lock_version++;
            $item->save();

            return $item->refresh();
        }, 3);
    }

    /** Soft-delete a prepared item that is neither consumed nor owns a recipe. */
    public function deleteSemiFinished(MenuContext $context, string $id, int $version): void
    {
        DB::transaction(function () use ($context, $id, $version): void {
            $item = SemiFinishedProduct::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->whereKey($id)->lockForUpdate()->first()
                ?? throw MenuException::notFound('The prepared item was not found.');
            $this->assertVersion($item->lock_version, $version);
            $this->deletionGuard->assertSemiFinishedDeletable($context, $item->id);
            $item->delete();
        }, 3);
    }
}
