<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class IdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
