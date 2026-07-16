<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

class ReservationActionRequest extends ReservationRequest
{
    public function rules(): array
    {
        $needsTable = $this->routeIs('tables.reservations.assign');
        $needsReason = $this->routeIs('tables.reservations.cancel');
        $allowChannel = $this->routeIs('tables.reservations.confirm');

        return [...$this->scopeRules(),
            'expected_version' => $this->version(),
            'table_id' => [$needsTable ? 'required' : 'prohibited', 'ulid'],
            'reason' => [$needsReason ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:500'],
            'confirmation_channel' => [$allowChannel ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:32'],
        ];
    }
}
