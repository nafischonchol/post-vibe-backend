<?php

namespace App\Http\Requests\Admin\Account;

use App\Http\Requests\BaseFormRequest;

class TransactionFilterRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'account_id' => ['nullable'],
            'type' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
