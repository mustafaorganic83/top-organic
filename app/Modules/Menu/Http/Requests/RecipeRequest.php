<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Covers creating a recipe header and drafting a version (with its BOM). Read,
 * publish and activate carry no body, so they use the base request directly.
 */
class RecipeRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('recipes.versions.draft')) {
            return [...$this->scopeRules(),
                'yield_quantity' => ['sometimes', 'numeric', 'min:0'],
                'yield_unit' => ['sometimes', 'string', 'max:24'],
                'waste_bps' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
                'calories' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'nutrition' => ['sometimes', 'nullable', 'array'],
                'instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
                'components' => ['required', 'array', 'min:1'],
                'components.*.component_type' => ['required', Rule::in(['stock_item', 'semi_finished_product'])],
                'components.*.component_id' => ['required', 'ulid'],
                'components.*.quantity' => ['required', 'numeric', 'gt:0'],
                'components.*.unit' => ['required', 'string', 'max:24'],
                'components.*.waste_bps' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
                'components.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            ];
        }

        return [...$this->scopeRules(),
            'owner_type' => ['required', Rule::in(['product_variant', 'semi_finished_product'])],
            'owner_id' => ['required', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
