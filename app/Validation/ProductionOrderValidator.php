<?php

namespace App\Validation;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderValidator
{
    public static function rules(): array
    {
        return [
            'branch_id' => ['required','integer','min:1'],
            'warehouse_id' => ['required','integer','min:1'],
            'prepared_recipe_id' => ['required','integer','min:1'],
            'planned_qty' => ['required','numeric','min:0.000001'],
            'actual_qty' => ['nullable','numeric','min:0'],
            'uom_id' => ['nullable','integer','min:1'],
            'status' => ['required','in:PLANNED,RELEASED,COMPLETED,CANCELLED'],
            'scheduled_at' => ['nullable','date'],
            'started_at' => ['nullable','date'],
            'completed_at' => ['nullable','date','after_or_equal:started_at'],
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
