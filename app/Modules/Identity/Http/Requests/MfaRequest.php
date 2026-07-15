<?php

namespace App\Modules\Identity\Http\Requests;

class MfaRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:512'],
            'response' => ['required', 'string', 'max:512'],
        ];
    }
}
