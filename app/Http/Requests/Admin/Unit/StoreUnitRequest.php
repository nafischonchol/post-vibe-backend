<?php

namespace App\Http\Requests\Admin\Unit;

use App\Http\Requests\BaseFormRequest;

class StoreUnitRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
