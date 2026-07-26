<?php

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecipeComponentValidator
{
    public static function rules(): array
    {
        return [
            'component_type' => ['required', Rule::in(['stock_item','semi_finished_product','packaging','modifier_option'])],
            'component_id' => ['required','integer','min:1'],
            'quantity' => ['required','numeric','min:0.000001'],
            'waste_bps' => ['nullable','integer','min:0','max:100000'],
            'sort_order' => ['nullable','integer','min:0'],
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
