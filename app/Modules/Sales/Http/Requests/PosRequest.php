<?php

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Validation\Rule;

class PosRequest extends SalesRequest
{
    public function rules(): array
    {
        $rules = [...$this->scopeRules(),
            'shift_id' => ['sometimes', 'required', 'ulid'], 'drawer_id' => ['sometimes', 'required', 'ulid'],
            'drawer_session_id' => ['sometimes', 'required', 'ulid'], 'movement_id' => ['sometimes', 'required', 'ulid'],
            'table_id' => ['sometimes', 'required', 'ulid'], 'session_id' => ['sometimes', 'required', 'ulid'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'uppercase'],
            'opening_amount' => $this->amount(false), 'counted_amount' => $this->amount(false),
            'amount' => ['sometimes', 'required', 'integer', 'not_in:0'],
            'type' => ['sometimes', 'required', Rule::in(['cash_in', 'cash_out', 'sale', 'refund', 'adjustment'])],
            'reason' => ['sometimes', 'required', 'string', 'max:500'],
            'guest_count' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'expected_version' => ['sometimes', 'required', 'integer', 'min:0'],
            'client_operation_id' => $this->operation(false),
        ];
        $required = match ($this->route()?->getName()) {
            'sales.pos.shift.close' => ['expected_version'],
            'sales.pos.drawer.open' => ['shift_id', 'drawer_id', 'currency', 'opening_amount'],
            'sales.pos.drawer.close' => ['counted_amount', 'expected_version'],
            'sales.pos.movement' => ['type', 'amount', 'currency', 'client_operation_id'],
            'sales.pos.movement.reverse' => ['reason', 'client_operation_id'],
            'sales.pos.table.open' => ['table_id', 'guest_count'],
            'sales.pos.table.close' => ['expected_version'],
            default => [],
        };
        foreach ($required as $field) {
            $rules[$field] = array_values(array_diff($rules[$field], ['sometimes']));
            array_unshift($rules[$field], 'required');
        }

        return $rules;
    }
}
