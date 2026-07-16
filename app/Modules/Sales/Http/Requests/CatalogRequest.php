<?php

namespace App\Modules\Sales\Http\Requests;

class CatalogRequest extends SalesRequest
{
    public function rules(): array
    {
        return [...$this->scopeRules(),
            'barcode' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._-]+\z/'],
            'channel' => ['sometimes', 'string', 'in:pos,online,delivery,takeaway,dine_in'],
        ];
    }
}
