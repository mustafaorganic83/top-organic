<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Http\Requests;

class QueueRequest extends KitchenRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'station_id' => ['sometimes', 'nullable', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
