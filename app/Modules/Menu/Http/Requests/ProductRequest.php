<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

class ProductRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUpdate = $this->routeIs('menu.products.update');

        return [...$this->scopeRules(),
            'sku' => [$isUpdate ? 'prohibited' : 'required', 'string', 'max:96'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'ulid'],
            'tax_class_id' => ['sometimes', 'nullable', 'ulid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'string', 'max:32'],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_meal' => ['sometimes', 'boolean'],
            'calories' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'nutrition_summary' => ['sometimes', 'nullable', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expected_version' => $isUpdate ? $this->version() : ['prohibited'],
        ];
    }
}
