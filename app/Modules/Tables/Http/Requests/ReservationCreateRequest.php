<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

use Illuminate\Validation\Rule;

class ReservationCreateRequest extends ReservationRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'reservation_source_id' => ['sometimes', 'nullable', 'ulid'],
            'customer_id' => ['sometimes', 'nullable', 'ulid'],
            'room_id' => ['sometimes', 'nullable', 'ulid'],
            'channel' => ['sometimes', Rule::in(['walk_in', 'phone', 'call_center', 'online', 'whatsapp', 'ai', 'pos'])],
            'guest_name' => ['required_without:customer_id', 'string', 'max:255'],
            'guest_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'guest_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'party_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'area' => ['sometimes', 'nullable', Rule::in(['indoor', 'outdoor', 'any'])],
            'reserved_for' => ['sometimes', 'nullable', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:1440'],
            'is_walk_in' => ['sometimes', 'boolean'],
            'special_requests' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'confirmation_channel' => ['sometimes', 'nullable', Rule::in(['system', 'sms', 'whatsapp', 'email', 'phone'])],
            'client_operation_id' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
        ];
    }
}
