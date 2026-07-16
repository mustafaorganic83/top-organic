<?php

declare(strict_types=1);

namespace App\Modules\Tables\Http\Requests;

use Illuminate\Validation\Rule;

class FloorRequest extends ReservationRequest
{
    public function rules(): array
    {
        $creating = $this->routeIs('tables.floors.store');

        return [...$this->scopeRules(),
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'layout' => ['sometimes', 'array'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expected_version' => $this->version(! $creating),
        ];
    }
}
