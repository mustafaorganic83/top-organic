<?php

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchasePriceValidator
{
    public static function rules(): array
    {
        return [
            'item_id' => ['required','integer','min:1'],
            'supplier_id' => ['required','integer','min:1'],
            'uom_id' => ['nullable','integer','min:1'],
            'price' => ['required','numeric','min:0.000001'],
            'currency_id' => ['required','integer','min:1'],
            'effective_from' => ['required','date'],
            'effective_to' => ['nullable','date','after:effective_from'],
            'source' => ['nullable','in:MANUAL,GRN,IMPORT'],
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
