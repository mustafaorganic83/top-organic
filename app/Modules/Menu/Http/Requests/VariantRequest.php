<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

class VariantRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUpdate = $this->routeIs('menu.products.variants.update');

        return [...$this->scopeRules(),
            'code' => [$isUpdate ? 'prohibited' : 'required', 'string', 'max:64'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meal_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:128'],
            'calories' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expected_version' => $isUpdate ? $this->version() : ['prohibited'],
        ];
    }
}
