<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

use Illuminate\Validation\Rule;

class TableRequest extends ReservationRequest
{
    public function rules(): array
    {
        $creating = $this->routeIs('tables.tables.store');
        $occupancy = $this->routeIs('tables.tables.occupancy');
        $listing = $this->isMethod('GET');
        $occupancyRule = $occupancy ? ['required'] : ($listing ? ['sometimes'] : ['prohibited']);

        return [...$this->scopeRules(),
            'floor_id' => [$creating ? 'required' : 'sometimes', 'ulid'],
            'room_id' => ['sometimes', 'nullable', 'ulid'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'area' => ['sometimes', Rule::in(['indoor', 'outdoor'])],
            'shape' => ['sometimes', Rule::in(['square', 'round', 'rectangle'])],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'is_reservable' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'occupancy_status' => [...$occupancyRule,
                Rule::in(['available', 'reserved', 'occupied', 'held', 'blocked', 'cleaning'])],
            'expected_version' => $this->version($occupancy || $this->routeIs('tables.tables.update')),
        ];
    }
}
