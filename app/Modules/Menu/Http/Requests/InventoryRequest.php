<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Covers manual stock adjustments/production and explicit consumption of a
 * sold variant (the same engine the order-completion hook uses).
 */
class InventoryRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('inventory.consume')) {
            return [...$this->scopeRules(),
                'product_variant_id' => ['required', 'ulid'],
                'quantity' => ['required', 'numeric', 'gt:0'],
                'client_operation_id' => $this->operation(),
                'reference_type' => ['sometimes', 'nullable', 'string', 'max:32'],
                'reference_id' => ['sometimes', 'nullable', 'ulid'],
            ];
        }

        return [...$this->scopeRules(),
            'stockable_type' => ['required', Rule::in(['stock_item', 'semi_finished_product'])],
            'stockable_id' => ['required', 'ulid'],
            'reason' => ['sometimes', Rule::in(['production', 'adjustment', 'waste'])],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'unit' => ['required', 'string', 'max:24'],
            'unit_cost_amount' => ['sometimes', 'integer', 'min:0'],
            'client_operation_id' => $this->operation(false),
            'reference_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'reference_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }
}
