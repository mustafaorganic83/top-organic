<?php

namespace App\Modules\Identity\Http\Requests;

class OfflineGrantRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'ulid'],
            'device_id' => ['required', 'ulid'],
        ];
    }
}
