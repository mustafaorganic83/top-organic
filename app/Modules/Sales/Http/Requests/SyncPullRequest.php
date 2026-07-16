<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

class SyncPullRequest extends SalesRequest
{
    public function rules(): array
    {
        $limit = (int) config('sales.sync.pull_page_limit', 200);

        return [...$this->scopeRules(),
            'stream' => ['sometimes', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]{1,63}\z/'],
            'cursor' => ['sometimes', 'integer', 'min:0', 'max:9223372036854775807'],
            'limit' => ['sometimes', 'integer', 'min:1', "max:{$limit}"],
        ];
    }

    public function stream(): string
    {
        return (string) $this->validated('stream', 'default');
    }

    public function cursor(): int
    {
        return (int) $this->validated('cursor', 0);
    }

    public function limit(): ?int
    {
        $limit = $this->validated('limit');

        return $limit === null ? null : (int) $limit;
    }
}
