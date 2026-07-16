<?php

namespace App\Modules\Sales\Http\Requests;

class OrderRequest extends SalesRequest
{
    public function rules(): array
    {
        $rules = [...$this->scopeRules(),
            'subtotal_amount' => ['prohibited'], 'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'], 'due_amount' => ['prohibited'], 'unit_price_amount' => ['prohibited'],
            'expected_version' => ['sometimes', 'integer', 'min:0'],
            'client_operation_id' => $this->operation(false),
            'type' => ['sometimes', 'string', 'in:dine_in,takeaway,delivery,online'],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            'source' => ['sometimes', 'string', 'in:pos,online,delivery,kiosk,mobile'],
            'source_reference' => ['nullable', 'string', 'max:128'], 'idempotency_key' => ['nullable', 'string', 'max:128'],
            'table_session_id' => ['nullable', 'ulid'], 'pos_shift_id' => ['nullable', 'ulid'], 'customer_id' => ['nullable', 'ulid'],
            'variant_id' => ['sometimes', 'ulid'], 'item_id' => ['sometimes', 'ulid'],
            'quantity' => $this->quantity(false), 'channel' => ['sometimes', 'string', 'in:pos,online,delivery,takeaway,dine_in'],
            'modifiers' => ['sometimes', 'array', 'max:50'], 'modifiers.*.option_id' => ['required', 'ulid'],
            'modifiers.*.quantity' => $this->quantity(false), 'course_number' => ['nullable', 'integer', 'min:1', 'max:100'],
            'seat_number' => ['nullable', 'integer', 'min:1', 'max:1000'], 'notes' => ['nullable', 'string', 'max:1000'],
            'state' => ['sometimes', 'string', 'in:confirmed,preparing,ready,completed,cancelled'],
            'address_snapshot' => ['sometimes', 'array', 'min:1', 'max:30'], 'contact_snapshot' => ['nullable', 'array', 'max:20'],
            'customer_address_id' => ['nullable', 'ulid'], 'provider' => ['nullable', 'string', 'max:64'],
            'provider_reference' => ['nullable', 'string', 'max:128'], 'fee_amount' => $this->amount(false),
            'promised_at' => ['nullable', 'date'], 'discount_type' => ['sometimes', 'string', 'in:fixed,percent'],
            'amount' => [...$this->amount(false), 'required_if:discount_type,fixed'],
            'rate_bps' => ['sometimes', 'integer', 'min:1', 'max:10000', 'required_if:discount_type,percent'],
            'maximum_amount' => $this->amount(false), 'reason' => ['sometimes', 'string', 'max:500'],
            'membership_id' => ['sometimes', 'ulid'], 'coupon_token' => ['sometimes', 'string', 'max:255'],
            'charges' => ['sometimes', 'array', 'max:50'], 'charges.*.code' => ['required', 'string', 'max:64'],
            'charges.*.name' => ['required', 'string', 'max:255'], 'charges.*.type' => ['sometimes', 'string', 'max:32'],
            'charges.*.calculation' => ['required', 'string', 'in:fixed,percent'],
            'charges.*.fixed_amount' => $this->amount(false), 'charges.*.rate_bps' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'charges.*.tax_class_id' => ['nullable', 'ulid'], 'selections' => ['sometimes', 'array', 'min:1', 'max:100'],
            'selections.*.item_id' => ['required', 'ulid'], 'selections.*.quantity' => $this->quantity(false),
            'source_order_id' => ['sometimes', 'ulid'], 'source_version' => ['sometimes', 'integer', 'min:0'],
            'target_table_session_id' => ['sometimes', 'ulid'],
            'delivery' => ['sometimes', 'array'], 'delivery.address_snapshot' => ['required_with:delivery', 'array', 'min:1', 'max:30'],
            'delivery.contact_snapshot' => ['nullable', 'array', 'max:20'], 'delivery.customer_address_id' => ['nullable', 'ulid'],
            'delivery.provider' => ['nullable', 'string', 'max:64'], 'delivery.provider_reference' => ['nullable', 'string', 'max:128'],
            'delivery.fee_amount' => $this->amount(false), 'delivery.promised_at' => ['nullable', 'date'],
        ];

        $required = match ($this->route()?->getName()) {
            'sales.orders.store' => ['type', 'currency', 'client_operation_id'],
            'sales.orders.items.store' => ['expected_version', 'variant_id', 'quantity', 'client_operation_id'],
            'sales.orders.items.update' => ['expected_version', 'client_operation_id'],
            'sales.orders.items.destroy', 'sales.orders.place', 'sales.orders.recalculate' => ['expected_version', 'client_operation_id'],
            'sales.orders.customer' => ['expected_version', 'client_operation_id'],
            'sales.orders.delivery' => ['expected_version', 'address_snapshot', 'client_operation_id'],
            'sales.orders.state' => ['expected_version', 'state', 'client_operation_id'],
            'sales.orders.discount.manual' => ['expected_version', 'discount_type', 'reason', 'client_operation_id'],
            'sales.orders.discount.membership' => ['expected_version', 'membership_id', 'client_operation_id'],
            'sales.orders.discount.coupon' => ['expected_version', 'coupon_token', 'client_operation_id'],
            'sales.orders.charges', 'sales.orders.service-charge' => ['expected_version', 'charges', 'client_operation_id'],
            'sales.orders.tip' => ['expected_version', 'amount', 'client_operation_id'],
            'sales.orders.split' => ['expected_version', 'selections', 'client_operation_id'],
            'sales.orders.merge' => ['expected_version', 'source_order_id', 'source_version', 'client_operation_id'],
            'sales.orders.transfer.order' => ['expected_version', 'source_order_id', 'source_version', 'client_operation_id'],
            'sales.orders.transfer.table' => ['expected_version', 'target_table_session_id', 'client_operation_id'],
            'sales.orders.transfer.customer' => ['expected_version', 'client_operation_id'],
            default => [],
        };
        foreach ($required as $field) {
            $rules[$field] = array_values(array_diff($rules[$field], ['sometimes', 'nullable']));
            array_unshift($rules[$field], 'required');
        }
        if ($this->routeIs('sales.orders.discount.manual')) {
            $rules['amount'] = ['required_if:discount_type,fixed', 'integer', 'min:0', 'max:9223372036854775807'];
            $rules['rate_bps'] = ['required_if:discount_type,percent', 'integer', 'min:1', 'max:10000'];
        }

        return $rules;
    }
}
