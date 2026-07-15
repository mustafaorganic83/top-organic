<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

class SyncCursorRequest extends SalesRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'stream' => ['sometimes', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]{1,63}\z/'],
            'sequence' => ['required', 'integer', 'min:0', 'max:9223372036854775807'],
            'resync' => ['sometimes', 'boolean'],
        ];
    }

    public function stream(): string
    {
        return (string) $this->validated('stream', 'default');
    }

    public function sequence(): int
    {
        return (int) $this->validated('sequence');
    }

    public function resync(): bool
    {
        return (bool) $this->validated('resync', false);
    }
}
