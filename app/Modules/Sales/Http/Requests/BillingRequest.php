<?php

namespace App\Modules\Sales\Http\Requests;

class BillingRequest extends SalesRequest
{
    public function rules(): array
    {
        $rules = [...$this->scopeRules(),
            'order_id' => ['sometimes', 'required', 'ulid'], 'expected_version' => ['sometimes', 'required', 'integer', 'min:0'],
            'payment_method_id' => ['sometimes', 'required', 'ulid'], 'amount' => $this->amount(false, 1),
            'idempotency_key' => ['sometimes', 'required', 'string', 'max:128'], 'client_operation_id' => $this->operation(false),
            'provider_reference' => ['nullable', 'string', 'max:128'], 'provider_snapshot' => ['sometimes', 'array', 'max:30'],
            'provider_snapshot.pan' => ['prohibited'], 'provider_snapshot.cvv' => ['prohibited'],
            'provider_snapshot.cvc' => ['prohibited'], 'provider_snapshot.card_number' => ['prohibited'],
            'gift_card_token' => ['nullable', 'string', 'min:16', 'max:255'],
            'reason' => ['sometimes', 'required', 'string', 'max:500'],
        ];
        $required = match ($this->route()?->getName()) {
            'sales.billing.capture' => ['order_id', 'expected_version', 'payment_method_id', 'amount', 'idempotency_key', 'client_operation_id'],
            'sales.billing.reverse' => ['amount', 'reason', 'client_operation_id'],
            default => [],
        };
        foreach ($required as $field) {
            $rules[$field] = array_values(array_diff($rules[$field], ['sometimes']));
            array_unshift($rules[$field], 'required');
        }

        return $rules;
    }
}
