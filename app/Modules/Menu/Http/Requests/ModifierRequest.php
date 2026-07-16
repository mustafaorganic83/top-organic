<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Covers modifier groups, options (extras), and attaching a group to a
 * product. The rule set switches on the route so one request serves the three
 * closely related shapes.
 */
class ModifierRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('menu.modifier-groups.options.store') => [...$this->scopeRules(),
                'code' => ['required', 'string', 'max:64'],
                'name' => ['required', 'string', 'max:255'],
                'surcharge_amount' => ['sometimes', 'integer', 'min:0'],
                'currency' => ['required', 'string', 'size:3'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
                'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            ],
            $this->routeIs('menu.products.modifiers.attach') => [...$this->scopeRules(),
                'modifier_group_id' => ['required', 'ulid'],
                'product_variant_id' => ['sometimes', 'nullable', 'ulid'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
                'min_selections' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
                'max_selections' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            ],
            default => [...$this->scopeRules(),
                'code' => ['required', 'string', 'max:64'],
                'name' => ['required', 'string', 'max:255'],
                'min_selections' => ['sometimes', 'integer', 'min:0', 'max:65535'],
                'max_selections' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
                'is_required' => ['sometimes', 'boolean'],
                'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            ],
        };
    }
}
