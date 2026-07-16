<?php

namespace App\Modules\Sales\Http\Requests;

class GiftCardRequest extends SalesRequest
{
    public function rules(): array
    {
        $rules = [...$this->scopeRules(),
            'token' => ['sometimes', 'required', 'string', 'min:16', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'uppercase'],
            'amount' => $this->amount(false, 1), 'initial_amount' => $this->amount(false),
            'customer_id' => ['nullable', 'ulid'], 'expires_at' => ['nullable', 'date', 'after:now'],
            'order_id' => ['sometimes', 'required', 'ulid'], 'transaction_id' => ['sometimes', 'required', 'ulid'],
            'client_operation_id' => $this->operation(false),
        ];
        $required = match ($this->route()?->getName()) {
            'sales.gifts.issue' => ['currency', 'initial_amount', 'client_operation_id'],
            'sales.gifts.load' => ['token', 'currency', 'amount', 'client_operation_id'],
            'sales.gifts.balance' => ['token'],
            'sales.gifts.redeem' => ['token', 'currency', 'amount', 'order_id', 'client_operation_id'],
            'sales.gifts.reverse' => ['transaction_id', 'client_operation_id'],
            default => [],
        };
        foreach ($required as $field) {
            $rules[$field] = array_values(array_diff($rules[$field], ['sometimes']));
            array_unshift($rules[$field], 'required');
        }

        return $rules;
    }
}
