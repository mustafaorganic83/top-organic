<?php

namespace App\Modules\Sales\Http\Requests;

class CustomerRequest extends SalesRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'name' => [$this->routeIs('sales.customers.store') ? 'required' : 'sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'email:rfc', 'max:255'],
            'locale' => ['nullable', 'string', 'max:16'], 'status' => ['sometimes', 'string', 'in:active,inactive'],
            'expected_version' => [$this->isMethod('patch') ? 'required' : 'sometimes', 'integer', 'min:0'],
            'membership_tier_id' => ['sometimes', 'required', 'ulid'],
            'membership_number' => ['sometimes', 'required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
