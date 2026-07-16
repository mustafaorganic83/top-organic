<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'tenant_slug' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'ulid'],
            'code' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['pos', 'kiosk', 'mobile', 'desktop', 'other'])],
            'public_key' => ['nullable', 'string', 'max:8192'],
            'key_fingerprint' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{64,128}\z/'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'os_version' => ['nullable', 'string', 'max:128'],
        ];
    }
}
