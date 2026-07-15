<?php

namespace App\Modules\Identity\Http\Requests;

class RefreshRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['refresh_token' => ['required', 'string', 'max:512']];
    }
}
