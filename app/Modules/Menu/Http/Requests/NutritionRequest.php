<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Covers creating an allergen and tagging an entity with one.
 */
class NutritionRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('menu.allergens.tag')) {
            return [...$this->scopeRules(),
                'allergen_id' => ['required', 'ulid'],
                'entity_type' => ['required', Rule::in(['product', 'stock_item', 'semi_finished_product'])],
                'entity_id' => ['required', 'ulid'],
                'is_traces' => ['sometimes', 'boolean'],
            ];
        }

        return [...$this->scopeRules(),
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:128'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
