<?php

namespace App\Modules\Identity\Http\Requests;

class LoginRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'tenant_slug' => ['required', 'string', 'max:100'],
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'ulid'],
            'device_id' => ['nullable', 'ulid'],
            'remembered_device_token' => ['nullable', 'string', 'max:512'],
        ];
    }
}
