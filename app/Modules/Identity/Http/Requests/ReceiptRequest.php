<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Validation\Rule;

class ReceiptRequest extends IdentityRequest
{
    public function rules(): array
    {
        return [
            'client_receipt_id' => ['required', 'ulid'],
            'result' => ['required', Rule::in(['success', 'failure', 'denied'])],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'metadata' => ['sometimes', 'array', 'max:50'],
        ];
    }
}
