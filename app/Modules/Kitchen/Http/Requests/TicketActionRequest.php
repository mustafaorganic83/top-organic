<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Requests;

class TicketActionRequest extends KitchenRequest
{
    public function rules(): array
    {
        $rules = [...$this->scopeRules(),
            'expected_version' => ['required', 'integer', 'min:0'],
            'client_operation_id' => $this->operation(),
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];

        if ($this->routeIs('kitchen.tickets.assign')) {
            $rules['chef_id'] = ['present', 'nullable', 'integer', 'min:1'];
        }

        if ($this->routeIs('kitchen.tickets.priority')) {
            $rules['is_priority'] = ['required', 'boolean'];
            $rules['priority'] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'];
        }

        return $rules;
    }
}
