<?php

declare(strict_types=1);

namespace App\Modules\Menu\Services;

use App\Models\Allergen;
use App\Models\EntityAllergen;
use App\Models\Product;
use App\Modules\Menu\Data\MenuContext;
use App\Modules\Menu\Exceptions\MenuException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Allergen catalog management and the nutrition/allergen roll-up for a product.
 * Calories come from the product's active recipe version when present (the
 * costed source of truth), falling back to the manually entered figure.
 */
final class NutritionService
{
    private const ENTITIES = ['product', 'stock_item', 'semi_finished_product'];

    /** @return Collection<int, Allergen> */
    public function allergens(MenuContext $context): Collection
    {
        return Allergen::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function createAllergen(MenuContext $context, array $data): Allergen
    {
        $exists = Allergen::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->where('code', $data['code'])->exists();
        if ($exists) {
            throw MenuException::conflict(MenuException::IN_USE, 'An allergen with this code already exists.');
        }

        return Allergen::withoutGlobalScopes()->create([
            'tenant_id' => $context->tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);
    }

    /** @param array<string, mixed> $data */
    public function tag(MenuContext $context, array $data): EntityAllergen
    {
        if (! in_array($data['entity_type'], self::ENTITIES, true)) {
            throw MenuException::invalid('The allergen entity type is not supported.');
        }
        $allergen = Allergen::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($data['allergen_id'])->first()
            ?? throw MenuException::notFound('The allergen was not found.');

        return EntityAllergen::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $context->tenantId,
                'allergen_id' => $allergen->id,
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
            ],
            ['is_traces' => $data['is_traces'] ?? false],
        );
    }

    /**
     * Nutrition & allergen summary for a product: calories (recipe-derived when
     * a version is active), the stored nutrition summary, and the tagged
     * allergens with their traces flag.
     *
     * @return array<string, mixed>
     */
    public function productNutrition(MenuContext $context, string $productId): array
    {
        $product = Product::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->with(['variants', 'allergenTags.allergen'])
            ->whereKey($productId)->first()
            ?? throw MenuException::notFound('The product was not found.');

        $recipeCalories = $product->variants
            ->map(fn ($v) => $v->recipe()->with('activeVersion')->first()?->activeVersion?->calories)
            ->filter()
            ->first();

        return [
            'product_id' => $product->id,
            'calories' => $recipeCalories ?? $product->calories,
            'calories_source' => $recipeCalories !== null ? 'recipe' : 'manual',
            'nutrition' => $product->nutrition_summary,
            'allergens' => $product->allergenTags->map(fn (EntityAllergen $tag) => [
                'code' => $tag->allergen?->code,
                'name' => $tag->allergen?->name,
                'icon' => $tag->allergen?->icon,
                'is_traces' => $tag->is_traces,
            ])->values()->all(),
        ];
    }
}
