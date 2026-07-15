<?php

namespace App\Modules\Sales\Http\Requests;

class KitchenRequest extends SalesRequest
{
    public function rules(): array
    {
        $rules = [...$this->scopeRules(),
            'order_id' => ['sometimes', 'required', 'ulid'], 'expected_version' => ['sometimes', 'required', 'integer', 'min:0'],
            'client_operation_id' => $this->operation(false), 'reason' => ['sometimes', 'string', 'max:500'],
        ];
        if ($this->routeIs('sales.kds.dispatch')) {
            $rules['order_id'] = ['required', 'ulid'];
            $rules['client_operation_id'] = $this->operation();
        } elseif ($this->routeIs('sales.kds.start', 'sales.kds.ready', 'sales.kds.bump', 'sales.kds.recall')) {
            $rules['expected_version'] = ['required', 'integer', 'min:0'];
            $rules['client_operation_id'] = $this->operation();
        }

        return $rules;
    }
}
