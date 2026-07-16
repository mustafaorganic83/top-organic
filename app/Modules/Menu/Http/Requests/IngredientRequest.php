<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Covers stock items (ingredients / packaging) and semi-finished products. The
 * rule set switches on the route.
 */
class IngredientRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('menu.semi-finished.store')) {
            return [...$this->scopeRules(),
                'sku' => ['required', 'string', 'max:96'],
                'name' => ['required', 'string', 'max:255'],
                'yield_unit' => ['required', 'string', 'max:24'],
                'yield_quantity' => ['sometimes', 'numeric', 'min:0'],
                'calories_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'nutrition' => ['sometimes', 'nullable', 'array'],
                'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            ];
        }

        $isUpdate = $this->routeIs('menu.ingredients.update');

        return [...$this->scopeRules(),
            'sku' => [$isUpdate ? 'prohibited' : 'required', 'string', 'max:96'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(['ingredient', 'packaging'])],
            'stock_unit' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:24'],
            'unit_cost_amount' => ['sometimes', 'integer', 'min:0'],
            'currency' => [$isUpdate ? 'sometimes' : 'required', 'string', 'size:3'],
            'default_waste_bps' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'calories_per_unit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'nutrition' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expected_version' => $isUpdate ? $this->version() : ['prohibited'],
        ];
    }
}
