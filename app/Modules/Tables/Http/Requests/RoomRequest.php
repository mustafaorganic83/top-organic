<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

use Illuminate\Validation\Rule;

class RoomRequest extends ReservationRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'floor_id' => ['sometimes', 'nullable', 'ulid'],
            'kind' => ['sometimes', Rule::in(['standard', 'vip', 'private'])],
            'capacity' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'minimum_spend_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'uppercase'],
            'requires_approval' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
