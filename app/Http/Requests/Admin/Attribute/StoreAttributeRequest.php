<?php

namespace App\Http\Requests\Admin\Attribute;

use App\Http\Requests\BaseFormRequest;
use App\Models\Attribute;
use Illuminate\Validation\Rule;

class StoreAttributeRequest extends BaseFormRequest
{
    public function rules(): array
    {

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('attributes', 'name'),
            ],
            'slug' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(array_values(Attribute::TYPE))],
            'values' => ['nullable'],
            'is_active' => ['boolean'],
            'is_default_specification' => ['boolean'],
        ];
    }
}
