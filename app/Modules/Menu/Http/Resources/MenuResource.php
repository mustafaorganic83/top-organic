<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Resources;

use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockItem;

/**
 * Stateless formatters that turn Menu aggregates into stable API payloads.
 * Kept free of business logic so controllers stay thin and responses stay
 * consistent across list, show, and mutation endpoints.
 */
final class MenuResource
{
    /** @return array<string, mixed> */
    public static function category(Category $c): array
    {
        return [
            'id' => $c->id, 'parent_id' => $c->parent_id, 'code' => $c->code, 'name' => $c->name,
            'description' => $c->description, 'image_url' => $c->image_url,
            'sort_order' => $c->sort_order, 'status' => $c->status, 'lock_version' => $c->lock_version,
            'media' => $c->relationLoaded('media') ? $c->media->map(self::media(...))->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function product(Product $p): array
    {
        return [
            'id' => $p->id, 'category_id' => $p->category_id, 'tax_class_id' => $p->tax_class_id,
            'sku' => $p->sku, 'name' => $p->name, 'description' => $p->description, 'type' => $p->type,
            'is_sellable' => $p->is_sellable, 'is_meal' => $p->is_meal, 'calories' => $p->calories,
            'nutrition_summary' => $p->nutrition_summary, 'sort_order' => $p->sort_order,
            'status' => $p->status, 'lock_version' => $p->lock_version,
            'variants' => $p->relationLoaded('variants') ? $p->variants->map(self::variant(...))->values()->all() : null,
            'media' => $p->relationLoaded('media') ? $p->media->map(self::media(...))->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function variant(ProductVariant $v): array
    {
        return [
            'id' => $v->id, 'product_id' => $v->product_id, 'code' => $v->code, 'name' => $v->name,
            'meal_size' => $v->meal_size, 'barcode' => $v->barcode, 'calories' => $v->calories,
            'sort_order' => $v->sort_order, 'status' => $v->status, 'lock_version' => $v->lock_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function modifierGroup(ModifierGroup $g): array
    {
        return [
            'id' => $g->id, 'code' => $g->code, 'name' => $g->name,
            'min_selections' => $g->min_selections, 'max_selections' => $g->max_selections,
            'is_required' => $g->is_required, 'status' => $g->status, 'lock_version' => $g->lock_version,
            'options' => $g->relationLoaded('options') ? $g->options->map(fn ($o) => [
                'id' => $o->id, 'code' => $o->code, 'name' => $o->name,
                'surcharge_amount' => $o->surcharge_amount, 'currency' => $o->currency,
                'sort_order' => $o->sort_order, 'status' => $o->status,
            ])->values()->all() : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function media(MediaAsset $m): array
    {
        return [
            'id' => $m->id, 'entity_type' => $m->entity_type, 'entity_id' => $m->entity_id,
            'kind' => $m->kind, 'url' => $m->url, 'thumbnail_url' => $m->thumbnail_url,
            'alt_text' => $m->alt_text, 'is_primary' => $m->is_primary, 'sort_order' => $m->sort_order,
            'metadata' => $m->metadata, 'status' => $m->status, 'lock_version' => $m->lock_version,
        ];
    }

    /** @return array<string, mixed> */
    public static function stockItem(StockItem $s): array
    {
        return [
            'id' => $s->id, 'sku' => $s->sku, 'name' => $s->name, 'kind' => $s->kind,
            'stock_unit' => $s->stock_unit, 'unit_cost_amount' => $s->unit_cost_amount,
            'currency' => $s->currency, 'default_waste_bps' => $s->default_waste_bps,
            'calories_per_unit' => $s->calories_per_unit, 'nutrition' => $s->nutrition,
            'status' => $s->status, 'lock_version' => $s->lock_version,
        ];
    }
}
