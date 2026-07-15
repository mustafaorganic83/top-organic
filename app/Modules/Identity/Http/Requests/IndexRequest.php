<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Validation\Rule;

class IndexRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'pending', 'authorized', 'revoked'])],
            'category' => ['sometimes', 'string', 'max:48', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'branch_id' => ['sometimes', 'ulid'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
