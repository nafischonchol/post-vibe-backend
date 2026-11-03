<?php

namespace App\Http\Requests\Admin\Account;

use App\Http\Requests\BaseFormRequest;

class AddFundsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
