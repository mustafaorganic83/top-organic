<?php

namespace App\Modules\Sales\Http\Requests;

class PrintRequest extends SalesRequest
{
    public function rules(): array
    {
        $rules = [...$this->scopeRules(),
            'payload_type' => ['sometimes', 'required', 'string', 'in:kitchen_ticket,receipt,invoice,barcode_label,qr_verification'],
            'document_id' => ['sometimes', 'required', 'ulid'], 'printer_id' => ['nullable', 'ulid'],
            'idempotency_key' => ['sometimes', 'required', 'string', 'max:128'],
            'client_operation_id' => $this->operation(false), 'expected_version' => ['sometimes', 'required', 'integer', 'min:0'],
            'error_code' => ['sometimes', 'required', 'string', 'max:64', 'regex:/\A[A-Z0-9_]+\z/'],
            'error_message' => ['sometimes', 'required', 'string', 'max:1000'],
        ];
        $required = match ($this->route()?->getName()) {
            'sales.printing.store' => ['payload_type', 'document_id', 'idempotency_key'],
            'sales.printing.complete', 'sales.printing.retry' => ['expected_version'],
            'sales.printing.fail' => ['expected_version', 'error_code', 'error_message'],
            default => [],
        };
        foreach ($required as $field) {
            $rules[$field] = array_values(array_diff($rules[$field], ['sometimes']));
            array_unshift($rules[$field], 'required');
        }

        return $rules;
    }
}
