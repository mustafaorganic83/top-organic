<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Validation\Rule;

class RoleRequest extends IdentityRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80', 'regex:/\A[A-Za-z0-9 _-]+\z/'],
            'label' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'permission_ids' => ['sometimes', 'array', 'max:200'],
            'permission_ids.*' => ['required', 'ulid', 'distinct'],
        ];
    }
}
