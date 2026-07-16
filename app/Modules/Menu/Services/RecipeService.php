<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\SemiFinishedProduct;
use App\Models\StockItem;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use App\Modules\Menu\Services\Concerns\GuardsMenuWrites;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The recipe builder and version lifecycle. A recipe belongs to one producible
 * (a product variant or a semi-finished product). Versions move through
 * draft -> published -> active -> archived: drafting captures the BOM,
 * publishing freezes an immutable costed + nutrition snapshot, and activating
 * pins exactly one live version (used by POS pricing and inventory
 * consumption).
 */
final class RecipeService
{
    use GuardsMenuWrites;

    private const OWNERS = ['product_variant', 'semi_finished_product'];

    public function __construct(private readonly RecipeCostingService $costing) {}

    /** @return Collection<int, Recipe> */
    public function list(MenuContext $context): Collection
    {
        return Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->with('activeVersion')->orderBy('name')->get();
    }

    public function find(MenuContext $context, string $id): Recipe
    {
        return Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->with(['versions.components', 'activeVersion.components'])
            ->whereKey($id)->first()
            ?? throw MenuException::notFound('The recipe was not found.');
    }

    /** @param array<string, mixed> $data */
    public function create(MenuContext $context, array $data): Recipe
    {
        if (! in_array($data['owner_type'], self::OWNERS, true)) {
            throw MenuException::invalid('The recipe owner type is not supported.');
        }
        $exists = Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('owner_type', $data['owner_type'])->where('owner_id', $data['owner_id'])->exists();
        if ($exists) {
            throw MenuException::conflict(MenuException::IN_USE, 'This item already has a recipe.');
        }

        return Recipe::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'owner_type' => $data['owner_type'],
            'owner_id' => $data['owner_id'],
            'name' => $data['name'],
            'status' => 'active',
            'lock_version' => 0,
        ]);
    }

    /**
     * Draft a new version: persists the BOM lines with a live cost snapshot so
     * the builder can preview cost immediately. Not yet costed for sale — that
     * is frozen at publish. Each draft gets the next revision number.
     *
     * @param  array<string, mixed>  $data
     */
    public function draftVersion(MenuContext $context, string $recipeId, array $data): RecipeVersion
    {
        return DB::transaction(function () use ($context, $recipeId, $data): RecipeVersion {
            $recipe = $this->lockRecipe($context, $recipeId);
            $components = $data['components'] ?? [];
            if ($components === []) {
                throw MenuException::invalid('A recipe version needs at least one component.');
            }
            $yield = (float) ($data['yield_quantity'] ?? 1);
            $waste = (int) ($data['waste_bps'] ?? 0);
            $costed = $this->costing->cost($context, $components, $yield, $waste);

            $revision = (int) (RecipeVersion::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('recipe_id', $recipe->id)->max('revision') ?? 0) + 1;

            $version = RecipeVersion::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'recipe_id' => $recipe->id,
                'revision' => $revision,
                'state' => 'draft',
                'yield_quantity' => $yield,
                'yield_unit' => $data['yield_unit'] ?? 'portion',
                'waste_bps' => $waste,
                'ingredient_cost_amount' => $costed['ingredient_cost'],
                'recipe_cost_amount' => $costed['recipe_cost'],
                'currency' => $costed['currency'],
                'calories' => $data['calories'] ?? null,
                'nutrition' => $data['nutrition'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'lock_version' => 0,
            ]);
            $this->writeComponents($context, $version, $costed['lines']);

            return $version->load('components');
        }, 3);
    }

    /**
     * Publish a draft: re-costs from current component prices and freezes the
     * snapshot (ingredient cost, recipe cost, food cost basis). Nutrition
     * calories, if omitted on the draft, are rolled up from components here.
     */
    public function publishVersion(MenuContext $context, string $recipeId, string $versionId): RecipeVersion
    {
        return DB::transaction(function () use ($context, $recipeId, $versionId): RecipeVersion {
            $version = $this->lockVersion($context, $recipeId, $versionId);
            if ($version->state !== 'draft') {
                throw MenuException::invalidState('Only a draft version can be published.');
            }
            $lines = $version->components->map(fn (RecipeComponent $c) => [
                'component_type' => $c->component_type, 'component_id' => $c->component_id,
                'quantity' => $c->quantity, 'unit' => $c->unit, 'waste_bps' => $c->waste_bps,
                'sort_order' => $c->sort_order,
            ])->all();
            $costed = $this->costing->cost($context, $lines, (float) $version->yield_quantity, $version->waste_bps);
            $version->ingredient_cost_amount = $costed['ingredient_cost'];
            $version->recipe_cost_amount = $costed['recipe_cost'];
            $version->currency = $costed['currency'];
            $version->calories ??= $this->rollUpCalories($context, $version);
            $version->state = 'published';
            $version->published_by = $context->userId;
            $version->published_at = now();
            $version->lock_version++;
            $version->save();
            $this->writeComponents($context, $version, $costed['lines']);

            return $version->load('components');
        }, 3);
    }

    /**
     * Activate a published version: archives any currently active version and
     * pins this one on the recipe. From here POS pricing and inventory
     * consumption resolve this version.
     */
    public function activateVersion(MenuContext $context, string $recipeId, string $versionId): RecipeVersion
    {
        return DB::transaction(function () use ($context, $recipeId, $versionId): RecipeVersion {
            $recipe = $this->lockRecipe($context, $recipeId);
            $version = $this->lockVersion($context, $recipeId, $versionId);
            if ($version->state !== 'published') {
                throw MenuException::invalidState('Only a published version can be activated.');
            }
            RecipeVersion::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
                ->where('recipe_id', $recipe->id)->where('state', 'active')
                ->update(['state' => 'archived']);
            $version->state = 'active';
            $version->activated_at = now();
            $version->lock_version++;
            $version->save();
            $recipe->active_version_id = $version->id;
            $recipe->lock_version++;
            $recipe->save();

            return $version->load('components');
        }, 3);
    }

    /**
     * Costing read-out for a recipe's active (or latest) version: recipe cost,
     * food cost %, yield and waste.
     *
     * @return array<string, mixed>
     */
    public function costReport(MenuContext $context, string $recipeId): array
    {
        $recipe = $this->find($context, $recipeId);
        $version = $recipe->activeVersion
            ?? $recipe->versions->sortByDesc('revision')->first()
            ?? throw MenuException::notFound('The recipe has no versions to cost.');

        $salePrice = $recipe->owner_type === 'product_variant'
            ? $this->costing->variantSalePrice($context, $recipe->owner_id)
            : null;

        return [
            'recipe_id' => $recipe->id,
            'version_id' => $version->id,
            'revision' => $version->revision,
            'state' => $version->state,
            'currency' => $version->currency,
            'ingredient_cost_amount' => $version->ingredient_cost_amount,
            'recipe_cost_amount' => $version->recipe_cost_amount,
            'yield_quantity' => $version->yield_quantity,
            'yield_unit' => $version->yield_unit,
            'waste_bps' => $version->waste_bps,
            'sale_price_amount' => $salePrice,
            'food_cost_bps' => $this->costing->foodCostBps($version->recipe_cost_amount, $salePrice),
        ];
    }

    private function lockRecipe(MenuContext $context, string $id): Recipe
    {
        return Recipe::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($id)->lockForUpdate()->first()
            ?? throw MenuException::notFound('The recipe was not found.');
    }

    private function lockVersion(MenuContext $context, string $recipeId, string $versionId): RecipeVersion
    {
        return RecipeVersion::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('recipe_id', $recipeId)->whereKey($versionId)
            ->with('components')->lockForUpdate()->first()
            ?? throw MenuException::notFound('The recipe version was not found.');
    }

    /**
     * Replace the version's component lines with the freshly costed set.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeComponents(MenuContext $context, RecipeVersion $version, array $lines): void
    {
        RecipeComponent::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('recipe_version_id', $version->id)->delete();
        foreach ($lines as $line) {
            RecipeComponent::withoutGlobalScopes()->create([
                'tenant_id' => $context->tenantId,
                'recipe_version_id' => $version->id,
                'component_type' => $line['component_type'],
                'component_id' => $line['component_id'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'waste_bps' => $line['waste_bps'],
                'unit_cost_amount' => $line['unit_cost_amount'],
                'line_cost_amount' => $line['line_cost_amount'],
                'sort_order' => $line['sort_order'],
            ]);
        }
    }

    /** Roll component calories up to a per-yield-unit calorie figure. */
    private function rollUpCalories(MenuContext $context, RecipeVersion $version): ?int
    {
        $total = 0;
        $seen = false;
        foreach ($version->components as $component) {
            $perUnit = $component->component_type === 'stock_item'
                ? StockItem::withoutGlobalScopes()->whereKey($component->component_id)->value('calories_per_unit')
                : SemiFinishedProduct::withoutGlobalScopes()->whereKey($component->component_id)->value('calories_per_unit');
            if ($perUnit !== null) {
                $seen = true;
                $total += (int) round($perUnit * (float) $component->quantity);
            }
        }
        if (! $seen) {
            return null;
        }
        $yield = (float) $version->yield_quantity > 0 ? (float) $version->yield_quantity : 1.0;

        return (int) round($total / $yield);
    }
}
