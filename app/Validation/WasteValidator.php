<?php

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WasteValidator
{
    public static function rules(): array
    {
        return [
            'waste_type' => ['required','in:PRIMARY,DEPARTMENT,OUTPUT,OTHER'],
            'production_order_id' => ['nullable','integer','min:1'],
            'item_id' => ['nullable','integer','min:1'],
            'qty' => ['nullable','numeric','min:0'],
            'uom_id' => ['nullable','integer','min:1'],
            'pct' => ['nullable','numeric','min:0','lt:1'],
            'department_id' => ['nullable','integer','min:1'],
            'warehouse_id' => ['nullable','integer','min:1'],
            'reason' => ['nullable','string','max:512'],
            'occurred_at' => ['required','date'],
        ];
    }

    /** @throws ValidationException */
    public static function validate(array $data): array
    {
        $v = Validator::make($data, self::rules());
        if ($v->fails()) {
            throw new ValidationException($v);
        }
        return $v->validated();
    }
}
