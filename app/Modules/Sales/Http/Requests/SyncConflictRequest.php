<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

class SyncConflictRequest extends SalesRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('sales.pagination.maximum', 100)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'resolution' => ['sometimes', 'string', 'in:accept_remote,keep_local,discard'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
