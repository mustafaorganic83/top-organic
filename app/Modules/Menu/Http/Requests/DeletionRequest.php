<?php

declare(strict_types=1);

namespace App\Modules\Menu\Http\Requests;

/**
 * Shared request for the menu deletion endpoints. Deletions are optimistic-lock
 * guarded like every other write, so the caller must send the version it saw.
 */
class DeletionRequest extends MenuRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'expected_version' => $this->version(),
        ];
    }
}
