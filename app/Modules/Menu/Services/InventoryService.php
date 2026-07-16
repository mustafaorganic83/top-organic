<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\InventoryMovement;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\StockLevel;
use App\Modules\Menu\Data\MenuContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Branch-scoped inventory: on-hand stock levels, the append-only movement
 * ledger, manual adjustments, and — the automation the menu module exists to
 * power — automatic consumption of ingredients when a sold item is completed.
 *
 * Consumption resolves the sold variant's active recipe version and explodes
 * its BOM, recursing into semi-finished products, then deducts each raw stock
 * item. Every deduction is written as a ledger row keyed by a client operation
 * id so a replayed offline command is idempotent.
 */
final class InventoryService
{
    private const BPS = 10000;

    /** @return Collection<int, StockLevel> */
    public function levels(MenuContext $context): Collection
    {
        return StockLevel::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)->get();
    }

    /** @return Collection<int, InventoryMovement> */
    public function movements(MenuContext $context, ?string $stockableType, ?string $stockableId): Collection
    {
        return InventoryMovement::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->when($stockableType !== null, fn ($q) => $q->where('stockable_type', $stockableType))
            ->when($stockableId !== null, fn ($q) => $q->where('stockable_id', $stockableId))
            ->orderByDesc('occurred_at')->limit(200)->get();
    }

    /**
     * Record a manual stock adjustment or production addition.
     *
     * @param  array<string, mixed>  $data
     */
    public function adjust(MenuContext $context, array $data): InventoryMovement
    {
        return DB::transaction(function () use ($context, $data): InventoryMovement {
            $movement = $this->record(
                $context,
                (string) $data['stockable_type'],
                (string) $data['stockable_id'],
                (string) ($data['reason'] ?? 'adjustment'),
                (float) $data['quantity_delta'],
                (string) $data['unit'],
                (int) ($data['unit_cost_amount'] ?? 0),
                $data['reference_type'] ?? null,
                $data['reference_id'] ?? null,
                $data['client_operation_id'] ?? null,
            );

            return $movement;
        }, 3);
    }

    /**
     * Automatically consume the ingredients for a sold quantity of a product
     * variant. Idempotent per client operation id: a replay returns the
     * already-recorded movements without double-deducting.
     *
     * @return array<int, InventoryMovement>
     */
    public function consumeForVariant(MenuContext $context, string $variantId, float $quantity, string $operation, ?string $referenceType = null, ?string $referenceId = null): array
    {
        return DB::transaction(function () use ($context, $variantId, $quantity, $operation, $referenceType, $referenceId): array {
            $existing = InventoryMovement::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('branch_id', $context->branchId)->where('client_operation_id', $operation)->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            $version = $this->activeVersionFor($context, 'product_variant', $variantId);
            if ($version === null) {
                return []; // No recipe: nothing to consume (e.g. a drink resold as-is).
            }

            $demand = [];
            $this->explode($context, $version->id, $quantity, $demand);

            $movements = [];
            foreach ($demand as $key => $line) {
                [$type, $id] = explode('|', $key, 2);
                $movements[] = $this->record($context, $type, $id, 'consumption',
                    -1 * $line['quantity'], $line['unit'], $line['unit_cost'],
                    $referenceType, $referenceId, $operation);
            }

            return $movements;
        }, 3);
    }

    /**
     * Recursively accumulate raw stock-item demand for one batch-scaled recipe
     * version. Semi-finished components recurse into their own active version;
     * raw stock items accumulate into $demand keyed by "type|id".
     *
     * @param  array<string, array{quantity: float, unit: string, unit_cost: int}>  $demand
     */
    private function explode(MenuContext $context, string $versionId, float $factor, array &$demand): void
    {
        $components = RecipeComponent::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('recipe_version_id', $versionId)->get();

        foreach ($components as $component) {
            $wasteFactor = (self::BPS + $component->waste_bps) / self::BPS;
            $needed = (float) $component->quantity * $factor * $wasteFactor;
            if ($component->component_type === 'semi_finished_product') {
                $nested = $this->activeVersionFor($context, 'semi_finished_product', $component->component_id);
                if ($nested !== null) {
                    $batchYield = (float) $nested->yield_quantity > 0 ? (float) $nested->yield_quantity : 1.0;
                    $this->explode($context, $nested->id, $needed / $batchYield, $demand);

                    continue;
                }
            }
            $key = $component->component_type.'|'.$component->component_id;
            $demand[$key] ??= ['quantity' => 0.0, 'unit' => $component->unit, 'unit_cost' => (int) $component->unit_cost_amount];
            $demand[$key]['quantity'] += $needed;
        }
    }

    /** Resolve the active recipe version for an owner, or null. */
    private function activeVersionFor(MenuContext $context, string $ownerType, string $ownerId): ?RecipeVersion
    {
        $recipe = Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->with('activeVersion')->first();

        return $recipe?->activeVersion;
    }

    /**
     * Append one ledger row and move the on-hand level by the same delta. The
     * unique (tenant, branch, client_operation_id, stockable) index makes a
     * replayed offline command a no-op at the database layer.
     */
    private function record(
        MenuContext $context,
        string $stockableType,
        string $stockableId,
        string $reason,
        float $quantityDelta,
        string $unit,
        int $unitCost,
        ?string $referenceType,
        ?string $referenceId,
        ?string $operation,
    ): InventoryMovement {
        $movement = InventoryMovement::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'branch_id' => $context->branchId,
            'stockable_type' => $stockableType,
            'stockable_id' => $stockableId,
            'reason' => $reason,
            'quantity_delta' => $quantityDelta,
            'unit' => $unit,
            'unit_cost_amount' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'client_operation_id' => $operation,
            'actor_id' => $context->userId,
            'device_id' => $context->deviceId,
            'occurred_at' => now(),
        ]);

        $level = StockLevel::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('branch_id', $context->branchId)
            ->where('stockable_type', $stockableType)->where('stockable_id', $stockableId)
            ->lockForUpdate()->first();
        if ($level === null) {
            StockLevel::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'branch_id' => $context->branchId,
                'stockable_type' => $stockableType,
                'stockable_id' => $stockableId,
                'quantity_on_hand' => $quantityDelta,
                'reorder_level' => 0,
                'lock_version' => 0,
            ]);
        } else {
            $level->quantity_on_hand = (float) $level->quantity_on_hand + $quantityDelta;
            $level->lock_version++;
            $level->save();
        }

        return $movement;
    }
}
