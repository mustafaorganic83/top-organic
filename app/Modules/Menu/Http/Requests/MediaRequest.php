<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

class MediaRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUpdate = $this->routeIs('menu.media.update');

        return [...$this->scopeRules(),
            'entity_type' => [$isUpdate ? 'prohibited' : 'required', Rule::in(['product', 'product_variant', 'category'])],
            'entity_id' => [$isUpdate ? 'prohibited' : 'required', 'ulid'],
            'kind' => ['sometimes', Rule::in(['image', 'video'])],
            'url' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:1024'],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expected_version' => $isUpdate ? $this->version() : ['prohibited'],
        ];
    }
}
