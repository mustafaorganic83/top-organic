<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

use Illuminate\Validation\Rule;

class WaitlistRequest extends ReservationRequest
{
    public function rules(): array
    {
        $joining = $this->routeIs('tables.waitlist.store');
        $cancelling = $this->routeIs('tables.waitlist.cancel');
        $action = $this->routeIs('tables.waitlist.notify')
            || $this->routeIs('tables.waitlist.seat') || $cancelling;

        return [...$this->scopeRules(),
            'customer_id' => ['sometimes', 'nullable', 'ulid'],
            'guest_name' => [$joining ? 'required_without:customer_id' : 'prohibited', 'string', 'max:255'],
            'guest_phone' => [$joining ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:32'],
            'party_size' => [$joining ? 'required' : 'prohibited', 'integer', 'min:1', 'max:1000'],
            'area' => [$joining ? 'sometimes' : 'prohibited', 'nullable', Rule::in(['indoor', 'outdoor', 'any'])],
            'quoted_wait_minutes' => [$joining ? 'sometimes' : 'prohibited', 'nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reason' => [$cancelling ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:500'],
            'expected_version' => $this->version($action),
        ];
    }
}
