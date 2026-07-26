<?php

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecipeVersionValidator
{
    public static function rules(): array
    {
        return [
            'revision' => ['nullable','integer','min:0'],
            'yield_quantity' => ['required','numeric','min:0.000001'],
            'waste_bps' => ['nullable','integer','min:0','max:100000'],
            'nutrition' => ['nullable','array'],
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
