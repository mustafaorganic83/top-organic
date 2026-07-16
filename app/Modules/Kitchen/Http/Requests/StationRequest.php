<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Requests;

use Illuminate\Validation\Rule;

class StationRequest extends KitchenRequest
{
    public function rules(): array
    {
        $isUpdate = $this->routeIs('kitchen.stations.update');

        return [...$this->scopeRules(),
            'code' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:64'],
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'station_type' => ['sometimes', Rule::in(['kitchen', 'bar', 'grill', 'cold', 'dessert', 'expo'])],
            'device_id' => ['sometimes', 'nullable', 'ulid'],
            'sla_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'default_prep_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'screen_config' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expected_version' => [$isUpdate ? 'required' : 'prohibited', 'integer', 'min:0'],
        ];
    }
}
