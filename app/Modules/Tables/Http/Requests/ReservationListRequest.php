<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

use Illuminate\Validation\Rule;

class ReservationListRequest extends ReservationRequest
{
    public function rules(): array
    {
        return [
            'state' => ['sometimes', Rule::in(['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'])],
            'channel' => ['sometimes', Rule::in(['walk_in', 'phone', 'call_center', 'online', 'whatsapp', 'ai', 'pos'])],
            'date' => ['sometimes', 'date'],
            'customer_id' => ['sometimes', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_filter([
            'state' => $this->validated('state'),
            'channel' => $this->validated('channel'),
            'date' => $this->validated('date'),
            'customer_id' => $this->validated('customer_id'),
        ], fn ($v) => $v !== null);
    }
}
