<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Resources;

use App\Models\InventoryMovement;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeVersion;
use App\Models\StockLevel;

/**
 * Formatters for the recipe/versioning/inventory side of the module.
 */
final class RecipeResource
{
    /** @return array<string, mixed> */
    public static function recipe(Recipe $r): array
    {
        return [
            'id' => $r->id, 'owner_type' => $r->owner_type, 'owner_id' => $r->owner_id,
            'name' => $r->name, 'active_version_id' => $r->active_version_id,
            'status' => $r->status, 'lock_version' => $r->lock_version,
            'active_version' => $r->relationLoaded('activeVersion') && $r->activeVersion !== null
                ? self::version($r->activeVersion) : null,
            'versions' => $r->relationLoaded('versions')
                ? $r->versions->map(self::version(...))->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function version(RecipeVersion $v): array
    {
        return [
            'id' => $v->id, 'recipe_id' => $v->recipe_id, 'revision' => $v->revision, 'state' => $v->state,
            'yield_quantity' => $v->yield_quantity, 'yield_unit' => $v->yield_unit, 'waste_bps' => $v->waste_bps,
            'ingredient_cost_amount' => $v->ingredient_cost_amount, 'recipe_cost_amount' => $v->recipe_cost_amount,
            'currency' => $v->currency, 'calories' => $v->calories, 'nutrition' => $v->nutrition,
            'instructions' => $v->instructions,
            'published_at' => $v->published_at?->toISOString(), 'activated_at' => $v->activated_at?->toISOString(),
            'lock_version' => $v->lock_version,
            'components' => $v->relationLoaded('components')
                ? $v->components->map(self::component(...))->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function component(RecipeComponent $c): array
    {
        return [
            'id' => $c->id, 'component_type' => $c->component_type, 'component_id' => $c->component_id,
            'quantity' => $c->quantity, 'unit' => $c->unit, 'waste_bps' => $c->waste_bps,
            'unit_cost_amount' => $c->unit_cost_amount, 'line_cost_amount' => $c->line_cost_amount,
            'sort_order' => $c->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    public static function stockLevel(StockLevel $l): array
    {
        return [
            'id' => $l->id, 'stockable_type' => $l->stockable_type, 'stockable_id' => $l->stockable_id,
            'quantity_on_hand' => $l->quantity_on_hand, 'reorder_level' => $l->reorder_level,
            'lock_version' => $l->lock_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function movement(InventoryMovement $m): array
    {
        return [
            'id' => $m->id, 'stockable_type' => $m->stockable_type, 'stockable_id' => $m->stockable_id,
            'reason' => $m->reason, 'quantity_delta' => $m->quantity_delta, 'unit' => $m->unit,
            'unit_cost_amount' => $m->unit_cost_amount, 'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id, 'occurred_at' => $m->occurred_at?->toISOString(),
        ];
    }
}
