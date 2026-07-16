<?php

namespace App\Modules\Sales\Http\Requests;

class IndexRequest extends SalesRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('sales.pagination.maximum', 100)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'state' => ['sometimes', 'string', 'max:32'], 'type' => ['sometimes', 'string', 'max:32'],
            'query' => ['sometimes', 'string', 'max:255'], 'channel' => ['sometimes', 'string', 'max:32'],
            'station_id' => ['sometimes', 'ulid'],
        ];
    }
}
