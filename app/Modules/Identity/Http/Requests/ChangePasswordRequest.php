<?php

namespace App\Modules\Identity\Http\Requests;

class ChangePasswordRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255'],
        ];
    }
}
