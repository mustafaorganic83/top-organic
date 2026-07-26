<?php

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CostSnapshotValidator
{
    public static function rules(): array
    {
        return [
            'entity_type' => ['required','in:ITEM,PREPARED,RECIPE'],
            'entity_id' => ['required','integer','min:1'],
            'as_of_date' => ['required','date'],
            'method' => ['required','in:LAST,MOVING_AVG,FIFO,STANDARD'],
            'unit_cost' => ['required','numeric','min:0'],
            'currency_id' => ['required','integer','min:1'],
            'details' => ['nullable','array'],
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
