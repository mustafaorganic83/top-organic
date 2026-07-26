<?php

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecipeCloneValidator
{
    public static function rules(): array
    {
        return [
            'source_recipe_id' => ['required','integer','min:1'],
            'new_code' => ['nullable','string','max:64'],
            'new_name_ar' => ['nullable','string','max:256'],
            'new_name_en' => ['nullable','string','max:256'],
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
