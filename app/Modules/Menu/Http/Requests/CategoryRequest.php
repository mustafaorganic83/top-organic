<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

class CategoryRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUpdate = $this->routeIs('menu.categories.update');

        return [...$this->scopeRules(),
            'code' => [$isUpdate ? 'prohibited' : 'required', 'string', 'max:64'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expected_version' => $isUpdate ? $this->version() : ['prohibited'],
        ];
    }
}
