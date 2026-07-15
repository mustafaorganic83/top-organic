<?php

namespace App\Modules\Identity\Http\Requests;

class RevokeRequest extends IdentityRequest
{
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'string', 'max:500']];
    }
}
