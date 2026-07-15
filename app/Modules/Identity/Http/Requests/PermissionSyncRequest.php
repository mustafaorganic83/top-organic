<?php

namespace App\Modules\Identity\Http\Requests;

class PermissionSyncRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'permission_ids' => ['required', 'array', 'max:200'],
            'permission_ids.*' => ['required', 'ulid', 'distinct'],
        ];
    }
}
